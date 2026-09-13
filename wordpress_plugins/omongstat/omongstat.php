<?php
/**
 * Plugin Name: OmongStat
 * Description: Self-hosted traffic analytics inside WordPress.
 * Version: 1.2.0
 * Requires PHP: 8.0
 */
defined('ABSPATH') || exit();
define('OMONSTAT_FILE', __FILE__);
define('OMONSTAT_DIR', plugin_dir_path(__FILE__));
define('OMONSTAT_URL', plugin_dir_url(__FILE__));
define('OMONSTAT_VERSION', '1.2.0');
define('OMONSTAT_SCHEMA_VERSION', 2);
foreach (
    ['security', 'install', 'migration', 'maintenance', 'collect', 'rest-stats', 'admin', 'import']
    as $omongstat_include
) {
    require_once OMONSTAT_DIR . 'includes/' . $omongstat_include . '.php';
}
unset($omongstat_include);
register_activation_hook(__FILE__, 'omongstat_activate');
add_action('plugins_loaded', 'omongstat_schedule_upgrade');
