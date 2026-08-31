<?php
declare(strict_types=1);

// Test-only control plane. Never invokes a worker or changes Cron timestamps.
define('ODBFS3_FIXTURE_HELPERS_ONLY', true);
require __DIR__ . '/media-test.php';

use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;

try {
    if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
        || getenv('WORDPRESS_DB_HOST') !== 'db:3306') { throw new RuntimeException('Wrong environment.'); }
    umask(0077);
    define('DISABLE_WP_CRON', true);
    require '/var/www/html/wp-load.php';
    if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST || $wpdb->prefix !== 'ziptest_') {
        throw new RuntimeException('Isolation guard failed.');
    }
    $mode = $argv[1] ?? '';
    $work = '/var/lib/odbfs3-work';
    $slug = 'ozeki-database-backup-for-s3';
    $plugin = $slug . '/' . $slug . '.php';
    $zip = $work . '/artifacts/preparation-9deffc6.zip';
    if (in_array($mode, ['update', 'verify'], true)) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '') || !hash_equals($argv[2], hash_file('sha256', $zip))) {
            throw new RuntimeException('Wrong release ZIP.');
        }
        $current = (new MediaJobController())->current();
        if ($current !== null && !$current->terminal()) { throw new RuntimeException('Cannot update an active job.'); }
        if (wp_next_scheduled(MediaJobController::HOOK, [$current?->id]) !== false) { throw new RuntimeException('Old event still scheduled.'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if ($mode === 'update') {
            $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
            if ($upgrader->install($zip, ['overwrite_package' => true]) !== true) { throw new RuntimeException('ZIP update failed.'); }
            echo "result=updated_from_zip; verify in a fresh process\n";
        } else {
            if (!is_plugin_active($plugin)) {
                $result = activate_plugin($plugin);
                if (is_wp_error($result)) { throw new RuntimeException('Activation failed.'); }
            }
            if (!method_exists(MediaJobController::class, 'enqueuePreparation')
                || !class_exists('SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client') || class_exists('Aws\\S3\\S3Client')) {
                throw new RuntimeException('Incorrect installed runtime.');
            }
            $archive = new ZipArchive();
            if ($archive->open($zip) !== true) { throw new RuntimeException('Cannot read ZIP.'); }
            $count = 0;
            for ($i = 0; $i < $archive->numFiles; ++$i) {
                $name = $archive->getNameIndex($i);
                if (!str_starts_with($name, $slug . '/') || str_contains($name, '..')) { throw new RuntimeException('Invalid ZIP path.'); }
                if (str_ends_with($name, '/')) { continue; }
                $installed = WP_PLUGIN_DIR . '/' . $name;
                if (!is_file($installed) || is_link($installed)
                    || !hash_equals(hash('sha256', $archive->getFromIndex($i)), hash_file('sha256', $installed))) {
                    throw new RuntimeException('Installed file differs from ZIP.');
                }
                ++$count;
            }
            $archive->close();
            $actual = 0;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WP_PLUGIN_DIR . '/' . $slug, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('Unexpected installed entry.'); }
                ++$actual;
            }
            if ($actual !== $count) { throw new RuntimeException('Extra installed files.'); }
            if (!copy(__DIR__ . '/media-observer.php', WP_CONTENT_DIR . '/mu-plugins/odbfs3-media-observer.php')) {
                throw new RuntimeException('Cannot install passive observer.');
            }
            echo json_encode(['result' => 'installed_zip_bytes_match', 'files' => $count, 'sha256' => $argv[2]], JSON_THROW_ON_ERROR) . "\n";
        }
    } elseif ($mode === 'enqueue') {
        $label = $argv[2] ?? '';
        if (!in_array($label, ['smoke', 'large'], true)) { throw new RuntimeException('Unknown fixture.'); }
        $controller = new MediaJobController();
        if ($controller->current() !== null && !$controller->current()->terminal()) { throw new RuntimeException('Job active.'); }
        $options = get_option('secure_s3_storage_settings', []);
        if (($options['bucket'] ?? '') !== 'ceri-secure-s3-storage-test' || ($options['region'] ?? '') !== 'ap-northeast-1'
            || ($options['prefix'] ?? '') !== 'wordpress-test/media-cron-ziptest/'
            || ($options['backup_schedule'] ?? '') !== 'disabled' || ($options['retention_keep_count'] ?? -1) !== 0) {
            throw new RuntimeException('Unexpected destination or automatic backup settings.');
        }
        $fixture = $work . '/fixtures/' . $label;
        $info = assertFixture($fixture);
        $root = WP_CONTENT_DIR . '/uploads/ziptest-preparation-' . $label . '-' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0755)) { throw new RuntimeException('Cannot create unique fixture root.'); }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture . '/uploads', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink()) { throw new RuntimeException('Fixture link rejected.'); }
            $relative = substr($file->getPathname(), strlen($fixture . '/uploads/'));
            $target = $root . '/' . $relative;
            if ($file->isDir()) { mkdir($target, 0755); }
            elseif (!$file->isFile() || !copy($file->getPathname(), $target)) { throw new RuntimeException('Fixture copy failed.'); }
        }
        // Validate copied synthetic bytes independently, without preparing any plan.
        $expected = fopen($fixture . '/expected.jsonl', 'rb'); $count = $bytes = 0;
        try {
            while (($line = fgets($expected)) !== false) {
                $entry = SecureS3StorageForWordpress\Backup\Media\MediaEntry::decode($line);
                if (filesize($root . '/' . $entry->path) !== $entry->size
                    || !hash_equals($entry->sha256, hash_file('sha256', $root . '/' . $entry->path))) {
                    throw new RuntimeException('Copied fixture does not match independent expectation.');
                }
                ++$count; $bytes += $entry->size;
            }
        } finally { fclose($expected); }
        if ($count !== $info['files'] || $bytes !== $info['bytes']) { throw new RuntimeException('Fixture totals differ.'); }
        update_option('upload_path', $root); wp_upload_dir(null, false, true);
        $started = microtime(true);
        $job = $controller->enqueuePreparation($work . '/plans');
        if ($job->checkpoint['phase'] !== 'enumerate' || file_exists($job->checkpoint['directory'] . '/paths.jsonl')
            || file_exists($job->checkpoint['directory'] . '/ready.json')) { throw new RuntimeException('Preparation ran during enqueue.'); }
        echo json_encode(['result' => 'queued_unprepared_fixture', 'id' => $job->id, 'label' => $label,
            'files' => $count, 'bytes' => $bytes, 'enqueue_seconds' => round(microtime(true)-$started, 3)], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($mode === 'status') {
        $job = (new MediaJobController())->current(); $s = $job?->checkpoint ?? [];
        $events = 0; $next = null;
        foreach (_get_cron_array() as $timestamp => $hooks) {
            foreach ($hooks[MediaJobController::HOOK] ?? [] as $event) { ++$events; $next = $timestamp; }
        }
        echo json_encode(['id' => $job?->id, 'status' => $job?->status, 'phase' => $s['phase'] ?? 'upload',
            'files' => $job?->processedFiles, 'bytes' => $job?->processedBytes, 'prepared_files' => $s['files'] ?? null,
            'queue_cursor' => $s['queue_cursor'] ?? null, 'sort_cursor' => $s['sort_cursor'] ?? null,
            'hash_offset' => $s['hash_offset'] ?? null, 'part_offset' => $s['part_offset'] ?? null,
            'part' => $s['part'] ?? null, 'attempts' => $job?->attempts, 'error' => $job?->errorCode,
            'cron_events' => $events, 'next_utc' => $next === null ? null : gmdate('c', $next)], JSON_THROW_ON_ERROR) . "\n";
    } else { throw new RuntimeException('Unknown mode.'); }
} catch (Throwable $e) {
    fwrite(STDERR, 'background_test_failed=' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
