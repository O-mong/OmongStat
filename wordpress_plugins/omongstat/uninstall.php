<?php

defined('WP_UNINSTALL_PLUGIN') || exit();
wp_clear_scheduled_hook('omongstat_upgrade_event');
wp_clear_scheduled_hook('omongstat_maintenance_event');
if (!get_option('omongstat_delete_on_uninstall', false)) {
    return;
}
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'omongstat`');
$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'omongstat_limits`');
foreach (
    [
        'schema_version',
        'version',
        'exclude_admin',
        'delete_on_uninstall',
        'retention_days',
        'backfill_cursor',
        'backfill_complete',
    ]
    as $key
) {
    delete_option('omongstat_' . $key);
}

$state_prefix = 'omongstat_' . substr(hash('sha256', $wpdb->prefix . 'omongstat'), 0, 12) . '_';
foreach (['backfill_cursor', 'backfill_complete'] as $name) {
    delete_option($state_prefix . $name);
}
delete_transient($state_prefix . 'upgrade_retry');
