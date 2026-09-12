<?php
// Execute with php inside wordpress-web after deployment. Removes only its own synthetic event.
require '/var/www/html/wp-load.php';
function smoke_check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
function local_http(string $path, ?array $payload = null): array
{
    $curl = curl_init('http://127.0.0.1' . $path);
    $headers = ['Host: ' . wp_parse_url(home_url('/'), PHP_URL_HOST)];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
    ]);
    $result = curl_exec($curl);
    if ($result === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    return [$status, substr($result, $header_size), substr($result, 0, $header_size)];
}
$table = omongstat_table_name();
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
$marker = 'smoke_' . bin2hex(random_bytes(16));
try {
    smoke_check(
        (int) get_option('omongstat_schema_version') === OMONSTAT_SCHEMA_VERSION,
        'installed schema is current',
    );
    $plugin_path = wp_parse_url(OMONSTAT_URL, PHP_URL_PATH);
    [$status] = local_http($plugin_path . 'assets/js/collector.js');
    smoke_check($status === 200, 'collector URL HTTP 200');
    $entry = omongstat_admin_assets();
    smoke_check($entry !== null, 'admin manifest is valid');
    [$status] = local_http($plugin_path . 'assets/admin/' . $entry['file']);
    smoke_check($status === 200, 'admin JS HTTP 200');
    [$status, $body] = local_http('/?omongstat-smoke=' . time());
    smoke_check(
        $status === 200 &&
            (str_contains($body, 'OmongStat') ||
                str_contains($body, 'omongstat-collector-js-extra')),
        'public page includes collector configuration',
    );
    if (preg_match('~<script[^>]*id="omongstat-collector-js"[^>]*src="([^"]+)"~', $body, $match)) {
        $optimized_path = wp_parse_url(html_entity_decode($match[1]), PHP_URL_PATH);
        [$optimized_status, $optimized_js] = local_http($optimized_path);
        smoke_check(
            $optimized_status === 200 && str_contains($optimized_js, 'OmongStat'),
            'Autoptimize collector bundle HTTP 200',
        );
        smoke_check(
            strpos($body, 'omongstat-collector-js-extra') <
                strpos($body, 'id="omongstat-collector-js"'),
            'configuration precedes optimized collector',
        );
    }
    $collect_path = wp_make_link_relative(rest_url('omongstat/v1/collect'));
    $payload = [
        'path' => '/omongstat-smoke-check/',
        'postId' => 0,
        'referrer' => '',
        'visitorId' => $marker,
        'sessionId' => $marker,
        'language' => 'ko-KR',
        'timezone' => 'UTC',
        'screenWidth' => 1200,
        'screenHeight' => 800,
        'eventType' => 'pageview',
    ];
    [$status, $body, $headers] = local_http($collect_path, $payload);
    smoke_check($status === 204 && $body === '', 'real HTTP collect returns empty 204');
    smoke_check(str_contains(strtolower($headers), 'no-store'), 'HTTP collect disables caching');
    $saved = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `$table` WHERE visitor_id = %s", $marker),
        ARRAY_A,
    );
    smoke_check(
        $saved && $saved['path'] === '/omongstat-smoke-check/' && strlen($saved['ip_hash']) === 64,
        'HTTP request saved correct path and hashed IP',
    );
    smoke_check(
        (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`") >= $before + 1,
        'DB count increases after HTTP collection',
    );
    $payload['path'] = 'https://outside.example/';
    [$status] = local_http($collect_path, $payload);
    smoke_check($status === 400, 'real HTTP invalid path returns 400');
    [$status] = local_http(wp_make_link_relative(rest_url('omongstat/v1/stats/summary')));
    smoke_check($status === 403, 'real HTTP anonymous stats returns 403');
    echo "SUCCESS: live HTTP smoke checks; pre-test event count=$before\n";
} finally {
    $wpdb->delete($table, ['visitor_id' => $marker], ['%s']);
}
