<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
$prefix = $argv[1] ?? '';
if (!preg_match('/^omongstat_test_[a-f0-9]{8}_$/D', $prefix)) {
    exit(2);
}
$GLOBALS['wp_filter']['option_active_plugins'][10][] = [
    'function' => fn() => [],
    'accepted_args' => 1,
];
$GLOBALS['wp_filter']['site_option_active_sitewide_plugins'][10][] = [
    'function' => fn() => [],
    'accepted_args' => 1,
];
require '/var/www/html/wp-load.php';
$wpdb->prefix = $prefix;
require dirname(__DIR__) . '/wordpress_plugins/omongstat/omongstat.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.44';
add_filter('omongstat_rate_limits', fn() => ['site' => 100, 'ip' => 2]);
$result = omongstat_check_rate_limit();
echo $result === null ? 'allowed' : (string) $result->get_error_data()['status'];
