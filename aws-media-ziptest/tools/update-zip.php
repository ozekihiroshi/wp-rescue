<?php
declare(strict_types=1);
try {
    if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
        || getenv('WORDPRESS_DB_HOST') !== 'db:3306') { throw new RuntimeException('Wrong environment.'); }
    define('DISABLE_WP_CRON', true);
    require '/var/www/html/wp-load.php';
    if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST || $wpdb->prefix !== 'ziptest_'
        || get_option('secure_s3_storage_background_job', null) !== null) { throw new RuntimeException('Unsafe update target.'); }
    $zip = '/var/lib/odbfs3-work/artifacts/ozeki-database-backup-for-s3-wpdbfix.zip';
    if (!preg_match('/^[a-f0-9]{64}$/', $argv[1] ?? '') || !hash_equals($argv[1], hash_file('sha256', $zip))) {
        throw new RuntimeException('Wrong update ZIP.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
    if ($upgrader->install($zip, ['overwrite_package' => true]) !== true) { throw new RuntimeException('ZIP update failed.'); }
    echo "result=test_plugin_updated_from_zip; verify in a fresh PHP process\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'update_failed=' . get_class($e) . ' line=' . $e->getLine() . "\n");
    exit(1);
}
