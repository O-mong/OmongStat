<?php

defined('ABSPATH') || exit;

function omongstat_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'omongstat';
}

function omongstat_activate(): void
{
    global $wpdb;

    $table_name = omongstat_table_name();

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        occured_at datetime NOT NULL,
        post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        path VARCHAR(2048) NOT NULL,
        referrer text NULL,
        user_agent text NULL,
        PRIMARY KEY (id),
        KEY occured_at (occured_at),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta($sql);

    update_option('omongstat_version', '1.0.0');

}