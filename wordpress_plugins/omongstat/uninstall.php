<?php

defined('WP_UNINSTALL_PLUGIN') || exit();
if (!get_option('omongstat_delete_on_uninstall', false)) {
    return;
}
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . 'omongstat`');
foreach (['schema_version', 'version', 'exclude_admin', 'delete_on_uninstall'] as $key) {
    delete_option('omongstat_' . $key);
}
