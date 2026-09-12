<?php

defined('ABSPATH') || exit();

add_action('rest_api_init', 'omongstat_register_stats_routes');

function omongstat_register_stats_routes(): void
{
    $routes = [
        'stats/summary',
        'stats/timeseries',
        'stats/pages',
        'stats/referrers',
        'stats/countries',
        'stats/technology',
        'events/recent',
    ];

    foreach ($routes as $route) {
        register_rest_route('omongstat/v1', '/' . $route, [
            'methods' => 'GET',
            'callback' => 'omongstat_stats',
            'permission_callback' => 'omongstat_stats_permission',
        ]);
    }
}

function omongstat_stats_permission($request)
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('omongstat_forbidden', 'Administrator access required.', [
            'status' => 403,
        ]);
    }

    if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return new WP_Error('omongstat_nonce', 'Invalid REST nonce.', ['status' => 403]);
    }

    return true;
}

function omongstat_date_range($request): array
{
    $timezone = wp_timezone();
    $today = new DateTimeImmutable('today', $timezone);
    $defaults = [
        'start' => $today->modify('-6 days')->format('Y-m-d'),
        'end' => $today->format('Y-m-d'),
    ];
    $dates = [];

    foreach ($defaults as $name => $default) {
        $value = $request->get_param($name) ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid date.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }
        $dates[$name] = $date;
    }

    if ($dates['end'] < $dates['start'] || $dates['start']->diff($dates['end'])->days > 365) {
        throw new InvalidArgumentException('Choose an ordered range of at most 366 days.');
    }

    return [$dates['start'], $dates['end']->modify('+1 day')];
}

function omongstat_stats_query(string $sql): array
{
    global $wpdb;

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error) {
        throw new RuntimeException($wpdb->last_error);
    }
    return $rows;
}

function omongstat_stats_where(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    global $wpdb;

    $utc = new DateTimeZone('UTC');
    return $wpdb->prepare(
        "event_type = 'pageview' AND occurred_at >= %s AND occurred_at < %s",
        $start->setTimezone($utc)->format('Y-m-d H:i:s'),
        $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    );
}

function omongstat_stats_summary(string $where): array
{
    $table = omongstat_table_name();
    $summary = omongstat_stats_query(
        "SELECT COUNT(*) AS pageviews,
                COUNT(DISTINCT NULLIF(visitor_id, '')) AS visitors,
                COUNT(DISTINCT NULLIF(session_id, '')) AS sessions
         FROM `$table`
         WHERE $where",
    )[0];

    $summary['total'] = omongstat_stats_query(
        "SELECT COUNT(*) AS count FROM `$table` WHERE event_type = 'pageview'",
    )[0]['count'];

    $today = new DateTimeImmutable('today', wp_timezone());
    $today_where = omongstat_stats_where($today, $today->modify('+1 day'));
    $summary['today'] = omongstat_stats_query(
        "SELECT COUNT(*) AS count FROM `$table` WHERE $today_where",
    )[0]['count'];

    return array_map('intval', $summary);
}

function omongstat_stats_timeseries(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    global $wpdb;

    $table = omongstat_table_name();
    $daily_queries = [];

    // Per-day UTC boundaries handle DST without requiring database timezone tables.
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        $where = omongstat_stats_where($day, $day->modify('+1 day'));
        $daily_queries[] = $wpdb->prepare(
            "SELECT %s AS label, COUNT(*) AS count FROM `$table` WHERE $where",
            $day->format('Y-m-d'),
        );
    }

    return omongstat_stats_query(implode(' UNION ALL ', $daily_queries));
}

function omongstat_stats_recent(string $where): array
{
    $table = omongstat_table_name();
    $rows = omongstat_stats_query(
        "SELECT id, occurred_at, path, post_id, referrer, country_code, source, is_bot
         FROM `$table`
         WHERE $where
         ORDER BY occurred_at DESC, id DESC
         LIMIT 50",
    );

    foreach ($rows as &$row) {
        $time = new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC'));
        $row['occurred_at'] = $time->format('Y-m-d\TH:i:s\Z');
        $row['occurred_at_local'] = $time->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }
    unset($row);

    return $rows;
}

function omongstat_stats_technology(string $where): array
{
    $table = omongstat_table_name();
    $result = [];

    foreach (['browser', 'operating_system', 'device_type', 'is_bot'] as $column) {
        $result[$column] = omongstat_stats_query(
            "SELECT $column AS label, COUNT(*) AS count
             FROM `$table`
             WHERE $where
             GROUP BY $column
             ORDER BY count DESC, label
             LIMIT 50",
        );
    }

    $result['screen'] = omongstat_stats_query(
        "SELECT CONCAT(screen_width, ' × ', screen_height) AS label, COUNT(*) AS count
         FROM `$table`
         WHERE $where AND screen_width IS NOT NULL AND screen_height IS NOT NULL
         GROUP BY screen_width, screen_height
         ORDER BY count DESC, label
         LIMIT 50",
    );

    return $result;
}

function omongstat_stats_distribution(string $name, string $where): array
{
    $table = omongstat_table_name();
    $columns = ['pages' => 'path', 'referrers' => 'referrer', 'countries' => 'country_code'];
    $column = $columns[$name];

    return omongstat_stats_query(
        "SELECT COALESCE(NULLIF($column, ''), 'Unknown') AS label, COUNT(*) AS count
         FROM `$table`
         WHERE $where
         GROUP BY $column
         ORDER BY count DESC, label
         LIMIT 50",
    );
}

function omongstat_stats($request)
{
    try {
        [$start, $end] = omongstat_date_range($request);
    } catch (InvalidArgumentException $error) {
        return new WP_Error('omongstat_dates', $error->getMessage(), ['status' => 400]);
    }

    $where = omongstat_stats_where($start, $end);
    $name = basename($request->get_route());

    try {
        $result = match ($name) {
            'summary' => omongstat_stats_summary($where),
            'timeseries' => omongstat_stats_timeseries($start, $end),
            'recent' => omongstat_stats_recent($where),
            'technology' => omongstat_stats_technology($where),
            default => omongstat_stats_distribution($name, $where),
        };
        return new WP_REST_Response($result, 200);
    } catch (Throwable $error) {
        error_log('OmongStat stats DB error: ' . $error->getMessage());
        return new WP_Error(
            'omongstat_db_error',
            'Statistics query failed. Check the server log.',
            ['status' => 500],
        );
    }
}
