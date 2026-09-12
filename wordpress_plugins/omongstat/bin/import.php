<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
$options = getopt('', ['file:', 'wp-load:', 'dry-run', 'trust-log-ip']);
if (empty($options['file'])) {
    fwrite(
        STDERR,
        "Usage: php bin/import.php --file=/tmp/access.log [--wp-load=/var/www/html/wp-load.php] [--dry-run] [--trust-log-ip]\n",
    );
    exit(1);
}
$bootstrap = $options['wp-load'] ?? dirname(__DIR__, 4) . '/wp-load.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "wp-load.php not found. Pass --wp-load explicitly.\n");
    exit(1);
}
require_once $bootstrap;
if (!function_exists('omongstat_import_log')) {
    fwrite(STDERR, "Activate OmongStat before importing.\n");
    exit(1);
}
try {
    $result = omongstat_import_log(
        $options['file'],
        isset($options['dry-run']),
        isset($options['trust-log-ip']),
    );
    echo wp_json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit($result['errors'] ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
