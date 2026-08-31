<?php
declare(strict_types=1);

// Isolated AWS test control plane. Does not enqueue or execute a media worker.
try {
    if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
        || getenv('WORDPRESS_DB_HOST') !== 'db:3306') { throw new RuntimeException('Wrong environment.'); }
    define('DISABLE_WP_CRON', true);
    require '/var/www/html/wp-load.php';
    if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST
        || $wpdb->prefix !== 'ziptest_' || get_option('siteurl') !== 'http://127.0.0.1:18084') {
        throw new RuntimeException('Isolation guard failed.');
    }
    $mode = $argv[1] ?? '';
    $slug = 'ozeki-database-backup-for-s3';
    $zip = '/var/lib/odbfs3-work/artifacts/admin-ui-71a511e.zip';
    $expectedHash = 'd2be0c939903d550ef73c525151b0ff755918e12795fa681c761f57eb1d4b682';
    $controller = new SecureS3StorageForWordpress\WordPress\MediaJobController();
    $job = $controller->current();
    $events = 0;
    foreach (_get_cron_array() as $hooks) {
        $events += count($hooks[SecureS3StorageForWordpress\WordPress\MediaJobController::HOOK] ?? []);
    }
    if (($job !== null && !$job->terminal()) || $events !== 0) { throw new RuntimeException('A media job is active or scheduled.'); }
    $options = get_option('secure_s3_storage_settings', []);
    if (($options['region'] ?? '') !== 'ap-northeast-1'
        || ($options['bucket'] ?? '') !== 'ceri-secure-s3-storage-test'
        || ($options['prefix'] ?? '') !== 'wordpress-test/media-cron-ziptest/'
        || ($options['backup_schedule'] ?? '') !== 'disabled'
        || ($options['retention_keep_count'] ?? -1) !== 0) { throw new RuntimeException('Unexpected test destination.'); }
    $before = [];
    foreach (['secure_s3_storage_settings', 'secure_s3_storage_background_job', 'active_plugins', 'upload_path', 'cron'] as $name) {
        $before[$name] = get_option($name, false);
    }
    if ($mode === 'update' || $mode === 'verify') {
        if (!hash_equals($expectedHash, hash_file('sha256', $zip))) { throw new RuntimeException('ZIP hash mismatch.'); }
        $archive = new ZipArchive();
        if ($archive->open($zip) !== true) { throw new RuntimeException('Unreadable ZIP.'); }
        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = $archive->getNameIndex($i);
            if (!str_starts_with($name, $slug . '/') || str_contains($name, '..') || str_contains($name, '\\')) {
                throw new RuntimeException('Invalid ZIP path.');
            }
        }
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if ($mode === 'update') {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            if ($upgrader->install($zip, ['overwrite_package' => true, 'clear_update_cache' => false]) !== true) {
                throw new RuntimeException('ZIP update failed.');
            }
        } else {
            $count = 0;
            for ($i = 0; $i < $archive->numFiles; ++$i) {
                $name = $archive->getNameIndex($i);
                if (str_ends_with($name, '/')) { continue; }
                $file = WP_PLUGIN_DIR . '/' . $name;
                if (!is_file($file) || is_link($file)
                    || !hash_equals(hash('sha256', $archive->getFromIndex($i)), hash_file('sha256', $file))) {
                    throw new RuntimeException('Installed file mismatch.');
                }
                ++$count;
            }
            $actual = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_PLUGIN_DIR . '/' . $slug, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('Unexpected installed entry.'); }
                ++$actual;
            }
            if ($count !== $actual || !is_plugin_active($slug . '/' . $slug . '.php')
                || !class_exists('SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client')
                || class_exists('Aws\\S3\\S3Client')) { throw new RuntimeException('Runtime mismatch.'); }
            $directory = (new SecureS3StorageForWordpress\WordPress\MediaWorkConfiguration())->directory();
            if ($directory !== '/var/lib/odbfs3-work/plans') { throw new RuntimeException('Unexpected private work path.'); }
            $ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if (count($ids) !== 1) { throw new RuntimeException('Test administrator missing.'); }
            wp_set_current_user((int)$ids[0]);
            $panel = new SecureS3StorageForWordpress\Admin\MediaBackupPanel();
            ob_start(); $panel->render(); $html = ob_get_clean();
            if (!str_contains($html, 'Start Media Backup') || preg_match('/data-media-start\s+disabled/', $html)) {
                throw new RuntimeException('Media start unavailable.');
            }
            echo json_encode(['files_match_zip' => $count, 'private_preflight' => 'passed', 'start_enabled' => true,
                'previous_job' => $job?->id, 'previous_status' => $job?->status], JSON_THROW_ON_ERROR) . PHP_EOL;
        }
        $archive->close();
    } elseif ($mode === 'connect') {
        $client = (new SecureS3StorageForWordpress\Aws\S3ClientFactory())->create($options['region']);
        $result = (new SecureS3StorageForWordpress\Aws\ConnectionTester())->test($client, $options['bucket'], $options['prefix']);
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        if (!$result['success']) { throw new RuntimeException('S3 connection test failed.'); }
    } elseif ($mode === 'inspect') {
        echo json_encode(['site' => get_option('siteurl'), 'job_id' => $job?->id, 'status' => $job?->status,
            'files' => $job?->processedFiles, 'bytes' => $job?->processedBytes, 'events' => $events,
            'constant_defined' => defined('ODBFS3_MEDIA_WORK_DIR'),
            'settings_hash' => hash('sha256', serialize($options)),
            'job_hash' => hash('sha256', serialize($before['secure_s3_storage_background_job'])),
            'upload_path' => get_option('upload_path')], JSON_THROW_ON_ERROR) . PHP_EOL;
    } else { throw new RuntimeException('Unknown mode.'); }
    foreach ($before as $name => $value) {
        if (get_option($name, false) !== $value) { throw new RuntimeException('Unexpected option mutation.'); }
    }
    echo 'result=' . $mode . '_passed; settings_jobs_activation_upload_path_cron=unchanged' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'admin_readiness_failed=' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}
