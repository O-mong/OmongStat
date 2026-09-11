<?php

defined( 'ABSPATH' ) || exit;

add_action('wp_enqueue_scripts', 'omongstat_enqueue_collector');

function omongstat_enqueue_collector(): void 
{
    $script_path = OMONSTAT_DIR . 'assets/js/collector.js';

    wp_enqueue_script(
        'omongstat-collector',
        OMONSTAT_URL . 'assets/js/collector.js',
        [],
        '1.0.0',
        true
    );

    wp_localize_script(
        'omongstat-collector',
        'OmongStat',
        [
            'collectUrl'    => rest_url('omongstat/v1/collect'),
            'postId'        => is_singular()
                ? get_queried_object_id()
                : 0,
        ]
    );
}

add_action('rest_api_init', 'omongstat_register_collect_route');

function omongstat_register_collect_route(): void
{
    register_rest_route(
        'omongstat/v1',
        '/collect',
        [
            'methods'               => 'POST',
            'callback'              => 'omongstat_collect',
            'permission_callback'   => '__return_true',
        ]
        );
}

function omongstat_collect(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;

    $data = $request->get_json_params();

    if (!is_array($data)) {
        return new WP_Error(
            'invalid_data',
            'invalid request body',
            ['status' => 400]
        );
    }

    $raw_path = (string) ($data['path'] ?? '/');
    $path = wp_parse_url($raw_path, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return new WP_Error(
            'invalid_path',
            'Path is too long.',
            ['status' => 400]
        );
    }

    $post_id = absint($data['postId'] ?? 0);
    $referrer = esc_url_raw((string) ($data['referrer'] ?? ''));

    $user_agent = sanitize_text_field(
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'omongstat',
        [
            'occured_at'    => gmdate('Y-m-d H:i:s'),
            'path'          => $path,
            'post_id'       => $post_id,
            'referrer'      => $referrer,
            'user_agent'    => $user_agent,
        ],
        [
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
        ]
    );

    if ($inserted === false) {
        error_log(
            'OmongStat DB error: ' . $wpdb->last_error
        );

        return new WP_Error(
            'db_error',
            'Failed to save analytics event.',
            ['status' => 500]
        );
    }

    return new WP_REST_Response(
      null,
      204
    );
}