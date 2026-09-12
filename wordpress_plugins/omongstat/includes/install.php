<?php

defined('ABSPATH') || exit();

function omongstat_table_name(): string
{
    global $wpdb;
    return $wpdb->prefix . 'omongstat';
}

function omongstat_schema_sql(): string
{
    global $wpdb;
    $table = omongstat_table_name();
    $collate = $wpdb->get_charset_collate();
    return "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        occurred_at datetime NOT NULL,
        event_type varchar(24) NOT NULL DEFAULT 'pageview',
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        path varchar(2048) NOT NULL,
        referrer text NULL,
        visitor_id varchar(64) NULL,
        session_id varchar(64) NULL,
        ip_hash char(64) NULL,
        country_code char(2) NULL,
        user_agent text NULL,
        language varchar(64) NULL,
        timezone varchar(128) NULL,
        screen_width int unsigned NULL,
        screen_height int unsigned NULL,
        browser varchar(32) NOT NULL DEFAULT 'Unknown',
        operating_system varchar(32) NOT NULL DEFAULT 'Unknown',
        device_type varchar(16) NOT NULL DEFAULT 'Unknown',
        is_bot tinyint unsigned NOT NULL DEFAULT 0,
        source varchar(16) NOT NULL DEFAULT 'collector',
        source_event_id char(64) NULL,
        PRIMARY KEY  (id),
        KEY occurred_at (occurred_at),
        KEY post_time (post_id,occurred_at),
        KEY path (path(191)),
        KEY visitor_time (visitor_id,occurred_at),
        KEY session_id (session_id),
        KEY country_code (country_code),
        KEY event_time (event_type,occurred_at),
        UNIQUE KEY source_event (source,source_event_id)
    ) $collate;";
}

function omongstat_activate(): void
{
    if (!omongstat_maybe_migrate()) {
        wp_die('OmongStat database upgrade failed. Check the server error log.');
    }
}
