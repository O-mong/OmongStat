<?php

defined('ABSPATH') || exit();

function omongstat_log_failure(string $operation, string $detail = ''): void
{
    // Correlate failures without copying URLs, SQL, headers, or payloads into logs.
    $fingerprint = substr(hash_hmac('sha256', $detail, wp_salt('auth')), 0, 12);
    error_log('OmongStat ' . $operation . ' failed; reference=' . $fingerprint);
}

function omongstat_clean_referrer(string $value): string
{
    $parts = wp_parse_url(esc_url_raw($value, ['http', 'https']));
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
        return '';
    }
    // Keep attribution paths, but never credentials, query strings, or fragments.
    $url = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (isset($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    return $url . ($parts['path'] ?? '');
}

function omongstat_url_origin(string $url): ?string
{
    $parts = wp_parse_url($url);
    if (
        !is_array($parts) ||
        empty($parts['host']) ||
        !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
    ) {
        return null;
    }
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    return strtolower($parts['scheme'] . '://' . $parts['host']) . ':' . $port;
}

function omongstat_check_origin(WP_REST_Request $request): ?WP_Error
{
    $origin = $request->get_header('origin');
    if (!$origin) {
        return null; // Some privacy clients omit Origin; rate limits still apply.
    }
    $allowed = (array) apply_filters('omongstat_allowed_origins', [home_url(), site_url()]);
    $normalized = omongstat_url_origin($origin);
    if (!$normalized || !in_array($normalized, array_map('omongstat_url_origin', $allowed), true)) {
        return new WP_Error('omongstat_origin', 'Origin is not allowed.', ['status' => 403]);
    }
    return null;
}

function omongstat_limits_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'omongstat_limits';
}

function omongstat_check_rate_limit(): ?WP_Error
{
    global $wpdb;
    $now = time();
    $window = intdiv($now, 60);
    $table = omongstat_limits_table();
    $limits = (array) apply_filters('omongstat_rate_limits', ['site' => 3000, 'ip' => 120]);
    $identity = omongstat_request_identity();
    $buckets = ['site' => 'site', 'ip' => $identity['ip_hash'] ?? 'unknown'];

    foreach ($buckets as $scope => $identity_key) {
        $maximum = max(1, min(100000, (int) ($limits[$scope] ?? 120)));
        $key = hash_hmac('sha256', $scope . ':' . $identity_key . ':' . $window, wp_salt('auth'));
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (bucket, hits, expires_at) VALUES (%s, 1, %d)
             ON DUPLICATE KEY UPDATE hits = LEAST(hits + 1, %d)",
                $key,
                ($window + 2) * 60,
                $maximum + 1,
            ),
        );
        if ($inserted === false) {
            omongstat_log_failure('rate-limit storage', $wpdb->last_error);
            return new WP_Error('omongstat_unavailable', 'Collection temporarily unavailable.', [
                'status' => 503,
            ]);
        }
        $hits = $wpdb->get_var($wpdb->prepare("SELECT hits FROM `$table` WHERE bucket = %s", $key));
        if ($wpdb->last_error || $hits === null) {
            omongstat_log_failure('rate-limit lookup', $wpdb->last_error);
            return new WP_Error('omongstat_unavailable', 'Collection temporarily unavailable.', [
                'status' => 503,
            ]);
        }
        if ($scope === 'site' && (int) $hits % 100 === 1) {
            $wpdb->query(
                $wpdb->prepare("DELETE FROM `$table` WHERE expires_at < %d LIMIT 500", $now),
            );
        }
        if ((int) $hits > $maximum) {
            return new WP_Error('omongstat_rate_limited', 'Too many collection requests.', [
                'status' => 429,
            ]);
        }
    }
    return null;
}

function omongstat_safe_asset(string $relative): bool
{
    if (
        $relative === '' ||
        str_contains($relative, "\0") ||
        str_contains($relative, '\\') ||
        str_starts_with($relative, '/')
    ) {
        return false;
    }
    if (is_link(OMONSTAT_DIR . 'assets')) {
        return false;
    }
    $base = OMONSTAT_DIR . 'assets/admin';
    $path = $base;
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
        $path .= '/' . $segment;
        if (is_link($path)) {
            return false;
        }
    }
    return !is_link($base) && is_file($path);
}

function omongstat_state_key(string $name): string
{
    return 'omongstat_' . substr(hash('sha256', omongstat_table_name()), 0, 12) . '_' . $name;
}
