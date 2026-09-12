<?php
// Run in a disposable PHP process; existing plugin hooks are disabled only for this process.
$GLOBALS['wp_filter']['option_active_plugins'][10][] = [
    'function' => fn() => [],
    'accepted_args' => 1,
];
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = [
    'function' => fn() => [],
    'accepted_args' => 1,
];
require '/var/www/html/wp-load.php';
$wpdb->prefix = 'omongstat_test_' . bin2hex(random_bytes(4)) . '_';
$table = $wpdb->prefix . 'omongstat';
$legacy_table = $wpdb->prefix . 'legacy_omongstat';
$schema = 0;
add_filter('pre_option_omongstat_schema_version', function () use (&$schema) {
    return $schema ?: '0';
});
add_filter(
    'pre_update_option_omongstat_schema_version',
    function ($value, $old) use (&$schema) {
        $schema = $value;
        return $old;
    },
    10,
    2,
);
require dirname(__DIR__) . '/wordpress_plugins/omongstat/omongstat.php';
$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS $message\n";
}
function request_event(array $payload)
{
    $request = new WP_REST_Request('POST', '/omongstat/v1/collect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode($payload));
    return apply_filters(
        'rest_post_dispatch',
        rest_do_request($request),
        rest_get_server(),
        $request,
    );
}
try {
    omongstat_activate();
    check($schema === OMONSTAT_SCHEMA_VERSION, 'fresh activation and schema version');
    check(!$wpdb->last_error, 'schema has no SQL error');
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
    check(
        in_array('occurred_at', $columns) && !in_array('occured_at', $columns),
        'correct timestamp column',
    );
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.42';
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'KR';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
    check(
        omongstat_request_identity()['country_code'] === null,
        'untrusted forwarded headers ignored',
    );
    add_filter('omongstat_trusted_proxies', fn() => ['127.0.0.1']);
    $payload = [
        'path' => '/actual/path/',
        'postId' => 17,
        'referrer' => 'https://example.org/a',
        'visitorId' => 'visitor_1234567890',
        'sessionId' => 'session_1234567890',
        'language' => 'ko-KR',
        'timezone' => 'Asia/Seoul',
        'screenWidth' => 1920,
        'screenHeight' => 1080,
        'eventType' => 'pageview',
    ];
    $response = request_event($payload);
    check($response->get_status() === 204, 'collect REST POST 204');
    check(
        str_contains($response->get_headers()['Cache-Control'] ?? '', 'no-store'),
        'collection is not cacheable',
    );
    $row = $wpdb->get_row("SELECT * FROM `$table`", ARRAY_A);
    check(
        $row['path'] === '/actual/path/' && (int) $row['post_id'] === 17,
        'path and integer insert formats',
    );
    check(
        $row['visitor_id'] === $payload['visitorId'] &&
            $row['session_id'] === $payload['sessionId'],
        'anonymous identifiers persisted',
    );
    check(
        $row['ip_hash'] === hash_hmac('sha256', '203.0.113.42', wp_salt('auth')) &&
            !in_array('ip', $columns),
        'IP stored only as HMAC',
    );
    check($row['country_code'] === 'KR', 'trusted Cloudflare country');
    check(
        $row['browser'] === 'Chrome' && $row['operating_system'] === 'Windows',
        'technology classification',
    );
    foreach (
        [
            'path' => 'https://evil.example/a',
            'visitorId' => 'bad',
            'screenWidth' => -1,
            'postId' => [],
            'eventType' => 'other',
            'referrer' => str_repeat('x', 4097),
        ]
        as $key => $value
    ) {
        $bad = $payload;
        $bad[$key] = $value;
        check(request_event($bad)->get_status() === 400, 'invalid payload rejected: ' . $key);
    }
    foreach (['//evil.example/a', '/%2Fevil.example', '/a\\b', '/x?secret=y'] as $path) {
        check(!omongstat_internal_path($path), 'reject unsafe path ' . $path);
    }
    check(
        rest_do_request(new WP_REST_Request('GET', '/omongstat/v1/collect'))->get_status() === 404,
        'GET collect is unavailable',
    );
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    check(count($admin) === 1, 'administrator available for permission tests');
    $request = new WP_REST_Request('GET', '/omongstat/v1/stats/summary');
    check(is_wp_error(omongstat_stats_permission($request)), 'anonymous stats denied');
    wp_set_current_user($admin[0]);
    check(is_wp_error(omongstat_stats_permission($request)), 'missing nonce denied');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    check(omongstat_stats_permission($request) === true, 'administrator with nonce allowed');
    foreach (
        ['summary', 'timeseries', 'pages', 'referrers', 'countries', 'technology', 'recent']
        as $name
    ) {
        $r = new WP_REST_Request(
            'GET',
            '/omongstat/v1/' . ($name === 'recent' ? 'events/recent' : 'stats/' . $name),
        );
        $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $result = rest_do_request($r);
        check($result->get_status() === 200, 'stats endpoint ' . $name);
        if ($name === 'summary') {
            check(
                $result->get_data()['total'] ===
                    (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`"),
                'summary equals DB count',
            );
        }
        if ($name === 'recent') {
            check($result->get_data()[0]['path'] === '/actual/path/', 'recent events reflect DB');
        }
    }
    $request->set_param('start', '2026-02-30');
    check(omongstat_stats($request)->get_error_data()['status'] === 400, 'invalid dates rejected');
    $log =
        '203.0.113.9 - - [12/Sep/2026:12:30:00 +0900] "GET /imported/ HTTP/1.1" 200 100 "-" "Mozilla/5.0 Chrome/130.0 Safari/537.36"';
    $file = tempnam('/tmp', 'omongstat-log-');
    file_put_contents(
        $file,
        $log . "\n" . $log . "\n" . str_replace('/imported/', '/wp-admin/', $log) . "\n",
    );
    $dry = omongstat_import_log($file, true);
    check(
        $dry['eligible'] === 1 && $dry['duplicates'] === 1 && $dry['excluded'] === 1,
        'import dry-run and filters',
    );
    $first = omongstat_import_log($file);
    $again = omongstat_import_log($file);
    check(
        $first['imported'] === 1 && $again['imported'] === 0 && $again['duplicates'] === 2,
        'import idempotency',
    );
    $imported = $wpdb->get_row("SELECT * FROM `$table` WHERE source = 'log_import'", ARRAY_A);
    check(
        $imported['visitor_id'] === null &&
            $imported['screen_width'] === null &&
            $imported['ip_hash'] === null,
        'missing log metadata remains NULL',
    );
    check($imported['occurred_at'] === '2026-09-12 03:30:00', 'log timestamps normalized to UTC');
    unlink($file);
    // Simulate an unavailable table without altering any live table.
    $wpdb->prefix .= 'missing_';
    $wpdb->suppress_errors(true);
    check(request_event($payload)->get_status() === 500, 'DB write failure returns 500');
    $request->set_param('start', wp_date('Y-m-d'));
    check(
        omongstat_stats($request)->get_error_data()['status'] === 500,
        'DB stats failure returns 500',
    );
    $wpdb->prefix = substr($wpdb->prefix, 0, -8);
    $wpdb->suppress_errors(false);
    // Legacy fixture: test the misspelling on the isolated table only.
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    $wpdb->query("ALTER TABLE `$table` CHANGE occurred_at occured_at datetime NOT NULL");
    $schema = 0;
    check(omongstat_maybe_migrate(), 'legacy timestamp migration');
    check(
        (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`") === $before,
        'migration preserves all events',
    );
    check(omongstat_maybe_migrate(), 'migration rerun is safe');
    $wpdb->prefix .= 'legacy_';
    $wpdb->query(
        "CREATE TABLE `$legacy_table` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, occured_at datetime NOT NULL, post_id bigint unsigned NOT NULL DEFAULT 0, path varchar(2048) NOT NULL, referrer text NULL, user_agent text NULL)",
    );
    $wpdb->insert($legacy_table, [
        'occured_at' => '2025-01-01 00:00:00',
        'path' => '/legacy/',
        'user_agent' => 'Mozilla/5.0 Firefox/120.0',
    ]);
    $schema = 0;
    check(omongstat_maybe_migrate(), 'upgrade original six-column schema');
    $legacy = $wpdb->get_row("SELECT * FROM `$legacy_table`", ARRAY_A);
    check(
        $legacy['path'] === '/legacy/' &&
            $legacy['occurred_at'] === '2025-01-01 00:00:00' &&
            $legacy['visitor_id'] === null &&
            $legacy['browser'] === 'Firefox',
        'legacy data preserved and UA backfilled',
    );
    $wpdb->query("ALTER TABLE `$legacy_table` ADD occured_at datetime NULL");
    $wpdb->query("UPDATE `$legacy_table` SET occured_at = '2024-01-01 00:00:00'");
    $schema = 0;
    check(
        !omongstat_maybe_migrate() && $schema === 0,
        'conflicting timestamps fail without advancing schema version',
    );
    check(
        $wpdb->get_var("SELECT occured_at FROM `$legacy_table`") === '2024-01-01 00:00:00',
        'conflicting legacy timestamp is retained',
    );
    echo "SUCCESS: $checks checks\n";
} finally {
    // Only this run's newly created fixture is removed; never the existing wp_omongstat table.
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
    $wpdb->query("DROP TABLE IF EXISTS `$legacy_table`");
}
