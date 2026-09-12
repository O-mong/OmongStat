<?php

defined('ABSPATH') || exit();

function omongstat_parse_log(string $line, bool $trust_ip = false): ?array
{
    if (
        !preg_match(
            '~^(?:.*?\s\|\s)?(?:\d{4}-\d{2}-\d{2}T\S+\s+)?(\S+) \S+ \S+ \[([^\]]+)\] "(GET|HEAD) ([^" ]+) HTTP/[^"]+" ([0-9]{3}) (?:[0-9]+|-) "([^"]*)" "([^"]*)"~',
            trim($line),
            $matches,
        )
    ) {
        return null;
    }
    [, $ip_address, $timestamp, , $request_target, $status, $referrer, $user_agent] = $matches;

    if ((int) $status < 200 || (int) $status >= 400) {
        return null;
    }
    $path = wp_parse_url($request_target, PHP_URL_PATH);
    if (
        !omongstat_internal_path($path) ||
        preg_match(
            '~/(?:wp-admin|wp-json|wp-content|wp-includes|wp-login\.php|wp-cron\.php|xmlrpc\.php|feed)(?:/|$)|\.(?!(?:html?|php)$)[a-z0-9]{1,8}$~i',
            $path,
        )
    ) {
        return null;
    }
    $query = wp_parse_url($request_target, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $params);
        if (isset($params['rest_route']) || isset($params['feed'])) {
            return null;
        }
    }
    $time = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $timestamp);
    if (!$time || $time->format('d/M/Y:H:i:s O') !== $timestamp) {
        throw new InvalidArgumentException('Invalid log timestamp');
    }
    $ua = substr($user_agent, 0, 4096);
    $tech = omongstat_technology($ua);
    if ($tech['is_bot']) {
        return null;
    }
    return array_merge(
        [
            'occurred_at' => $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
            'post_id' => 0,
            'path' => $path,
            'referrer' =>
                $referrer === '-'
                    ? null
                    : esc_url_raw(substr($referrer, 0, 4096), ['http', 'https']),
            'visitor_id' => null,
            'session_id' => null,
            'ip_hash' =>
                $trust_ip && filter_var($ip_address, FILTER_VALIDATE_IP)
                    ? hash_hmac('sha256', $ip_address, wp_salt('auth'))
                    : null,
            'country_code' => null,
            'user_agent' => $ua,
            'language' => null,
            'timezone' => null,
            'screen_width' => null,
            'screen_height' => null,
            'source' => 'log_import',
            'source_event_id' => hash('sha256', trim($line)),
        ],
        $tech,
    );
}

function omongstat_import_batch(array &$batch, array &$counts, bool $dry_run, string $seen): void
{
    global $wpdb;

    if (!$batch) {
        return;
    }
    $table = omongstat_table_name();
    $committed = 0;
    $duplicates = 0;
    if (!$dry_run && $wpdb->query('START TRANSACTION') === false) {
        throw new RuntimeException('Cannot begin import transaction.');
    }
    try {
        foreach ($batch as $event) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `$table` WHERE source = 'log_import' AND source_event_id = %s",
                    $event['source_event_id'],
                ),
            );
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
            if ($exists) {
                $duplicates++;
                continue;
            }
            if ($dry_run) {
                $added = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO `$seen` (id) VALUES (%s)",
                        $event['source_event_id'],
                    ),
                );
                if ($added === false) {
                    throw new RuntimeException($wpdb->last_error);
                }
                if ($added === 0) {
                    $duplicates++;
                } else {
                    $committed++;
                }
                continue;
            }
            if (omongstat_insert_event($event) === false) {
                throw new RuntimeException($wpdb->last_error);
            }
            $committed++;
        }
        if (!$dry_run && $wpdb->query('COMMIT') === false) {
            throw new RuntimeException($wpdb->last_error);
        }
        $counts[$dry_run ? 'eligible' : 'imported'] += $committed;
        $counts['duplicates'] += $duplicates;
    } catch (Throwable $error) {
        if (!$dry_run) {
            $wpdb->query('ROLLBACK');
        }
        error_log('OmongStat import DB error: ' . $error->getMessage());
        $counts['errors'] += count($batch);
    }
    $batch = [];
}

function omongstat_import_log(string $file, bool $dry_run = false, bool $trust_ip = false): array
{
    global $wpdb;
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Log file is not readable.');
    }
    $stream = fopen($file, 'rb');
    if (!$stream) {
        throw new RuntimeException('Cannot open log file.');
    }
    $counts = [
        'lines' => 0,
        'imported' => 0,
        'eligible' => 0,
        'excluded' => 0,
        'duplicates' => 0,
        'errors' => 0,
    ];
    $batch = [];
    $lock = 'omongstat_import_' . substr(hash('sha256', DB_NAME . omongstat_table_name()), 0, 32);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
        fclose($stream);
        throw new RuntimeException('Another import is running.');
    }
    $seen = 'omongstat_dry_' . bin2hex(random_bytes(8));
    if (
        $dry_run &&
        $wpdb->query("CREATE TEMPORARY TABLE `$seen` (id char(64) PRIMARY KEY) ENGINE=InnoDB") ===
            false
    ) {
        fclose($stream);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        throw new RuntimeException('Cannot create dry-run deduplication table.');
    }
    try {
        while (($line = fgets($stream, 65537)) !== false) {
            $counts['lines']++;
            if (strlen($line) >= 65536 && !str_ends_with($line, "\n")) {
                while (($tail = fgets($stream, 65537)) !== false && !str_ends_with($tail, "\n")) {
                }
                $counts['errors']++;
                continue;
            }
            try {
                $event = omongstat_parse_log($line, $trust_ip);
            } catch (Throwable $error) {
                $counts['errors']++;
                continue;
            }
            if (!$event) {
                $counts['excluded']++;
                continue;
            }
            $key = $event['source_event_id'];
            if (isset($batch[$key])) {
                $counts['duplicates']++;
                continue;
            }
            $batch[$key] = $event;
            if (count($batch) >= 250) {
                omongstat_import_batch($batch, $counts, $dry_run, $seen);
            }
        }
        if (!feof($stream)) {
            throw new RuntimeException('Log read failed.');
        }
        omongstat_import_batch($batch, $counts, $dry_run, $seen);
    } finally {
        fclose($stream);
        if ($dry_run) {
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$seen`");
        }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
    return $counts;
}
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('omongstat import', function ($args, $assoc) {
        try {
            $result = omongstat_import_log(
                $args[0] ?? '',
                isset($assoc['dry-run']),
                isset($assoc['trust-log-ip']),
            );
            WP_CLI::log(wp_json_encode($result));
            if ($result['errors']) {
                WP_CLI::error('Import completed with errors.');
            }
        } catch (Throwable $error) {
            WP_CLI::error($error->getMessage());
        }
    });
}
