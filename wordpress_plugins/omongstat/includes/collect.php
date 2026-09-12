<?php

defined('ABSPATH') || exit();
add_action('wp_enqueue_scripts', 'omongstat_enqueue_collector');

function omongstat_enqueue_collector(): void
{
    if (
        is_admin() ||
        (get_option('omongstat_exclude_admin', true) && current_user_can('manage_options'))
    ) {
        return;
    }
    $relative = 'assets/js/collector.js';
    if (!is_file(OMONSTAT_DIR . $relative)) {
        error_log('OmongStat: collector file missing');
        return;
    }
    wp_enqueue_script(
        'omongstat-collector',
        OMONSTAT_URL . $relative,
        [],
        (string) filemtime(OMONSTAT_DIR . $relative),
        true,
    );
    wp_localize_script('omongstat-collector', 'OmongStat', [
        'collectUrl' => wp_make_link_relative(rest_url('omongstat/v1/collect')),
        'postId' => is_singular() ? get_queried_object_id() : 0,
        'debug' => defined('WP_DEBUG') && WP_DEBUG,
    ]);
}
add_action('rest_api_init', function () {
    register_rest_route('omongstat/v1', '/collect', [
        'methods' => 'POST',
        'callback' => 'omongstat_collect',
        'permission_callback' => '__return_true',
    ]);
});
add_filter(
    'rest_post_dispatch',
    function ($response, $server, $request) {
        if (str_starts_with($request->get_route(), '/omongstat/v1/')) {
            $response->header('Cache-Control', 'no-store, private, max-age=0');
            $response->header('Pragma', 'no-cache');
        }
        return $response;
    },
    10,
    3,
);

function omongstat_bad_request(string $field): WP_Error
{
    return new WP_Error('omongstat_invalid_payload', 'Invalid field: ' . $field, ['status' => 400]);
}

function omongstat_internal_path($path): bool
{
    return is_string($path) &&
        strlen($path) <= 2048 &&
        preg_match('~^/(?!/)[^\x00-\x20\\\\?#]*$~D', $path) === 1 &&
        !preg_match('~[\x00-\x20\\\\]~', rawurldecode($path)) &&
        !str_starts_with(rawurldecode($path), '//');
}

function omongstat_technology(string $ua): array
{
    $bot = (bool) preg_match('/bot|crawler|spider|slurp|headless|curl|wget/i', $ua);
    $browser = 'Unknown';
    $browser_patterns = [
        'Edge' => '/Edg[eA\/]/i',
        'Opera' => '/OPR\//i',
        'Firefox' => '/Firefox|FxiOS/i',
        'Chrome' => '/Chrome|CriOS/i',
        'Safari' => '/Safari/i',
    ];
    foreach ($browser_patterns as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            $browser = $name;
            break;
        }
    }

    $os = 'Unknown';
    $os_patterns = [
        'Android' => '/Android/i',
        'iOS' => '/iPhone|iPad|iPod/i',
        'Windows' => '/Windows/i',
        'macOS' => '/Macintosh|Mac OS X/i',
        'Linux' => '/Linux/i',
    ];
    foreach ($os_patterns as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            $os = $name;
            break;
        }
    }
    if ($ua === '') {
        $device = 'Unknown';
    } elseif ($bot) {
        $device = 'Bot';
    } elseif (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobile|iPhone|iPod/i', $ua)) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }

    return [
        'browser' => $browser,
        'operating_system' => $os,
        'device_type' => $device,
        'is_bot' => (int) $bot,
    ];
}

function omongstat_request_identity(): array
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // Configure exact trusted reverse-proxy addresses; forwarded headers are otherwise ignored.
    $trusted = in_array($remote, (array) apply_filters('omongstat_trusted_proxies', []), true);
    $ip = $remote;
    if ($trusted) {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (filter_var($cf, FILTER_VALIDATE_IP)) {
            $ip = $cf;
        } else {
            $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $proxies = (array) apply_filters('omongstat_trusted_proxies', []);
            foreach (array_reverse($chain) as $candidate) {
                if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                    break;
                }
                $ip = $candidate;
                if (!in_array($candidate, $proxies, true)) {
                    break;
                }
            }
        }
    }
    $country = $trusted ? strtoupper($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') : '';
    return [
        'ip_hash' => filter_var($ip, FILTER_VALIDATE_IP)
            ? hash_hmac('sha256', $ip, wp_salt('auth'))
            : null,
        'country_code' =>
            preg_match('/^[A-Z]{2}$/D', $country) && $country !== 'XX' ? $country : null,
    ];
}

function omongstat_insert_event(array $event)
{
    global $wpdb;
    $integers = ['post_id', 'screen_width', 'screen_height', 'is_bot'];
    return $wpdb->insert(
        omongstat_table_name(),
        $event,
        array_map(fn($key) => in_array($key, $integers, true) ? '%d' : '%s', array_keys($event)),
    );
}

function omongstat_validate_payload($data): ?WP_Error
{
    if (!is_array($data)) {
        return omongstat_bad_request('body');
    }
    if (!omongstat_internal_path($data['path'] ?? null)) {
        return omongstat_bad_request('path');
    }
    foreach (['visitorId', 'sessionId'] as $field) {
        if (
            !is_string($data[$field] ?? null) ||
            !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $data[$field])
        ) {
            return omongstat_bad_request($field);
        }
    }
    if (($data['eventType'] ?? null) !== 'pageview') {
        return omongstat_bad_request('eventType');
    }
    if (!is_int($data['postId'] ?? null)) {
        return omongstat_bad_request('postId');
    }
    foreach (['referrer' => 4096, 'language' => 64, 'timezone' => 128] as $field => $limit) {
        if (!is_string($data[$field] ?? null) || strlen($data[$field]) > $limit) {
            return omongstat_bad_request($field);
        }
    }
    foreach (['screenWidth', 'screenHeight'] as $field) {
        if (!is_int($data[$field] ?? null) || $data[$field] < 0 || $data[$field] > 65535) {
            return omongstat_bad_request($field);
        }
    }
    $referrer = esc_url_raw($data['referrer'], ['http', 'https']);
    if ($data['referrer'] !== '' && (!$referrer || !wp_parse_url($referrer, PHP_URL_HOST))) {
        return omongstat_bad_request('referrer');
    }
    return null;
}

function omongstat_collect(WP_REST_Request $request)
{
    global $wpdb;
    if (strlen($request->get_body()) > 16384) {
        return new WP_Error('omongstat_payload_large', 'Payload too large.', ['status' => 413]);
    }
    $data = $request->get_json_params();
    $validation_error = omongstat_validate_payload($data);
    if ($validation_error) {
        return $validation_error;
    }

    $referrer = esc_url_raw($data['referrer'], ['http', 'https']);
    $ua = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 4096);
    $event = array_merge(
        [
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'event_type' => 'pageview',
            'path' => $data['path'],
            'post_id' => absint($data['postId']),
            'referrer' => $referrer,
            'visitor_id' => $data['visitorId'],
            'session_id' => $data['sessionId'],
            'language' => sanitize_text_field($data['language']),
            'timezone' => sanitize_text_field($data['timezone']),
            'screen_width' => $data['screenWidth'],
            'screen_height' => $data['screenHeight'],
            'user_agent' => $ua,
            'source' => 'collector',
        ],
        omongstat_request_identity(),
        omongstat_technology($ua),
    );
    if (omongstat_insert_event($event) === false) {
        error_log('OmongStat DB error: ' . $wpdb->last_error);
        return new WP_Error('omongstat_db_error', 'Failed to save analytics event.', [
            'status' => 500,
        ]);
    }
    return new WP_REST_Response(null, 204);
}
