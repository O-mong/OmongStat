<?php

defined('ABSPATH') || exit();

function omongstat_maybe_migrate(): bool
{
    global $wpdb;
    if ((int) get_option('omongstat_schema_version', 0) >= OMONSTAT_SCHEMA_VERSION) {
        return true;
    }
    $table = omongstat_table_name();
    // A database lock also protects concurrent requests during an upgrade.
    $lock = 'omongstat_' . substr(hash('sha256', DB_NAME . $table), 0, 40);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== 1) {
        return false;
    }
    try {
        if ((int) get_option('omongstat_schema_version', 0) >= OMONSTAT_SCHEMA_VERSION) {
            return true;
        }
        omongstat_migrate_timestamp($table);

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(omongstat_schema_sql());
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
        omongstat_verify_schema($table);
        omongstat_backfill_technology($table);

        update_option('omongstat_schema_version', OMONSTAT_SCHEMA_VERSION, false);
        return true;
    } catch (Throwable $error) {
        error_log('OmongStat migration: ' . $error->getMessage());
        return false;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

function omongstat_migrate_timestamp(string $table): void
{
    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if (!$exists) {
        return;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
    if (!in_array('occured_at', $columns, true)) {
        return;
    }

    if (!in_array('occurred_at', $columns, true)) {
        omongstat_migration_query(
            "ALTER TABLE `$table` CHANGE occured_at occurred_at datetime NOT NULL",
        );
        return;
    }

    omongstat_migration_query(
        "UPDATE `$table`
         SET occurred_at = occured_at
         WHERE occurred_at IS NULL OR occurred_at = '0000-00-00 00:00:00'",
    );

    // Retain the legacy column if any timestamps disagree.
    $conflicts = $wpdb->get_var(
        "SELECT COUNT(*) FROM `$table`
         WHERE occured_at IS NOT NULL AND occured_at <> occurred_at",
    );
    if ($wpdb->last_error || (int) $conflicts > 0) {
        throw new RuntimeException('Conflicting legacy timestamps; resolve before upgrading.');
    }

    omongstat_migration_query("ALTER TABLE `$table` DROP COLUMN occured_at");
}

function omongstat_migration_query(string $sql): void
{
    global $wpdb;

    if ($wpdb->query($sql) === false) {
        throw new RuntimeException($wpdb->last_error);
    }
}

function omongstat_verify_schema(string $table): void
{
    global $wpdb;

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
    foreach (
        [
            'id',
            'occurred_at',
            'event_type',
            'post_id',
            'path',
            'referrer',
            'visitor_id',
            'session_id',
            'ip_hash',
            'country_code',
            'user_agent',
            'language',
            'timezone',
            'screen_width',
            'screen_height',
            'browser',
            'operating_system',
            'device_type',
            'is_bot',
            'source',
            'source_event_id',
        ]
        as $column
    ) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('Missing column: ' . $column);
        }
    }
    $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
    $index_names = array_column($indexes, 'Key_name');
    foreach (
        [
            'PRIMARY',
            'occurred_at',
            'post_time',
            'path',
            'visitor_time',
            'session_id',
            'country_code',
            'event_time',
            'source_event',
        ]
        as $index
    ) {
        if (!in_array($index, $index_names, true)) {
            throw new RuntimeException('Missing index: ' . $index);
        }
    }
}

function omongstat_backfill_technology(string $table): void
{
    global $wpdb;

    // Classify legacy rows in bounded batches rather than loading the event table.
    $last_id = 0;
    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_agent FROM `$table` WHERE id > %d AND browser = 'Unknown' AND user_agent IS NOT NULL ORDER BY id LIMIT 250",
                $last_id,
            ),
            ARRAY_A,
        );
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
        foreach ($rows as $row) {
            if (
                $wpdb->update(
                    $table,
                    omongstat_technology($row['user_agent']),
                    ['id' => $row['id']],
                    ['%s', '%s', '%s', '%d'],
                    ['%d'],
                ) === false
            ) {
                throw new RuntimeException($wpdb->last_error);
            }
            $last_id = (int) $row['id'];
        }
    } while (count($rows) === 250);
}
