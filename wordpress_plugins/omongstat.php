<?php
/**
 * Plugin Name: OmongStat
 * Description: OmongStat is a simple and lightweight analytics plugin for WordPress. It allows you to track your website's traffic and user behavior without compromising your visitors' privacy.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

define('OMONSTAT_FILE', __FILE__);
define('OMONSTAT_DIR', plugin_dir_path(__FILE__));
define('OMONSTAT_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/collect.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook(
    __FILE__, 
    'omongstat_activate'
);