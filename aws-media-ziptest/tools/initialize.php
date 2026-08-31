<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
    || getenv('WORDPRESS_DB_HOST') !== 'db:3306') {
    throw new RuntimeException('Not the isolated test environment.');
}
// Suppress notification mail only in this initialization process.
function wp_mail(...$arguments): bool { return true; }
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST
    || $wpdb->prefix !== 'ziptest_') {
    throw new RuntimeException('Test isolation check failed.');
}
if (is_blog_installed()) {
    throw new RuntimeException('Already installed; refusing to initialize again.');
}
$password = trim(file_get_contents('/run/secrets/admin_password'));
if (strlen($password) !== 64) {
    throw new RuntimeException('Missing generated admin password.');
}
$result = wp_install('S3 Media ZIP Test — isolated AWS', 'ziptest_admin',
    'ziptest@example.invalid', false, '', $password, 'en_US');
unset($password, $result);
update_option('blog_public', 0);
update_option('default_ping_status', 'closed');
update_option('default_comment_status', 'closed');
update_option('timezone_string', 'Asia/Tokyo');
$directory = WP_CONTENT_DIR . '/mu-plugins';
if (!is_dir($directory) && !mkdir($directory, 0755)) {
    throw new RuntimeException('Cannot create test MU directory.');
}
$target = $directory . '/odbfs3-ziptest-cron-probe.php';
if (file_exists($target) || !copy(__DIR__ . '/cron-probe.php', $target)) {
    throw new RuntimeException('Cannot install test-only Cron probe.');
}
$event = wp_schedule_single_event(time() - 1, 'odbfs3_ziptest_cron_probe', [], true);
if (is_wp_error($event) || !$event) {
    throw new RuntimeException('Cannot schedule test Cron probe.');
}
echo "WordPress installed in isolated test DB; Cron probe scheduled.\n";
