<?php
declare(strict_types=1);

// Test host helper; not distributed. Never calls the worker's run()/tick().
use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\WordPressMediaSourceFactory;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaEntry;
use SecureS3StorageForWordpress\Backup\Media\MediaInventoryIO;
use SecureS3StorageForWordpress\Backup\Media\MediaInventorySorter;
use SecureS3StorageForWordpress\Backup\SecureTemporaryFile;

function assertFixture(string $fixture): array {
    $info = json_decode(file_get_contents($fixture . '/fixture-info.json'), true, 8, JSON_THROW_ON_ERROR);
    if (($info['format'] ?? '') !== 'odbfs3-synthetic-fixture' || ($info['version'] ?? 0) !== 1
        || !hash_equals($info['expected_sha256'], hash_file('sha256', $fixture . '/expected.jsonl'))) {
        throw new RuntimeException('Invalid fixture.');
    }
    return $info;
}
function compareExpected(string $fixture, string $manifest): void {
    $expected = static function () use ($fixture): Generator {
        $stream = MediaInventoryIO::openRead($fixture . '/expected.jsonl');
        try { while (($line = MediaInventoryIO::readLine($stream)) !== null) { yield MediaEntry::decode($line); } }
        finally { fclose($stream); }
    };
    $sorted = (new MediaInventorySorter())->sorted($expected(), '/var/lib/odbfs3-work');
    $actual = (new MediaManifest())->entries($manifest);
    while ($sorted->valid() || $actual->valid()) {
        if (!$sorted->valid() || !$actual->valid() || $sorted->current()->encode() !== $actual->current()->encode()) {
            throw new RuntimeException('Inventory differs from independent original fixture.');
        }
        $sorted->next(); $actual->next();
    }
}
function downloadObject(object $client, string $bucket, string $key, string $path): void {
    $stream = SecureTemporaryFile::openForWriting($path);
    try {
        $result = $client->getObject(['Bucket' => $bucket, 'Key' => $key,
            '@http' => ['sink' => $stream, 'connect_timeout' => 5, 'timeout' => 120]]);
        if (is_resource($stream)) { fflush($stream); }
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!in_array($result['ContentLength'], [$size, (string) $size], true)) { throw new RuntimeException('Short download.'); }
    } finally { if (is_resource($stream)) { fclose($stream); } }
}

try {
    if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest'
        || getenv('WORDPRESS_DB_HOST') !== 'db:3306') { throw new RuntimeException('Wrong environment.'); }
    umask(0077);
    // Only suppress spawning in this helper process; real HTTP WP-Cron is enabled.
    define('DISABLE_WP_CRON', true);
    require '/var/www/html/wp-load.php';
    if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST || $wpdb->prefix !== 'ziptest_') {
        throw new RuntimeException('Isolation guard failed.');
    }
    $mode = $argv[1] ?? '';
    $work = '/var/lib/odbfs3-work';
    $slug = 'ozeki-database-backup-for-s3';
    $plugin = $slug . '/' . $slug . '.php';
    if ($mode === 'install') {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $zip = $work . '/artifacts/' . $slug . '-0.1.1.zip';
        if (!preg_match('/^[a-f0-9]{64}$/', $argv[2] ?? '') || !hash_equals($argv[2], hash_file('sha256', $zip))
            || file_exists(WP_PLUGIN_DIR . '/' . $slug)) { throw new RuntimeException('ZIP hash or fresh install guard failed.'); }
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        if ($upgrader->install($zip) !== true) { throw new RuntimeException('ZIP installation failed.'); }
        $activation = activate_plugin($plugin);
        if (is_wp_error($activation) || !is_plugin_active($plugin)) { throw new RuntimeException('Activation failed.'); }
        if (!class_exists('SecureS3StorageForWordpressVendor\\Aws\\S3\\S3Client') || class_exists('Aws\\S3\\S3Client')) {
            throw new RuntimeException('Scoped SDK isolation failed.');
        }
        update_option('secure_s3_storage_settings', ['region' => 'ap-northeast-1',
            'bucket' => 'ceri-secure-s3-storage-test', 'prefix' => 'wordpress-test/media-cron-ziptest/',
            'backup_schedule' => 'disabled', 'retention_keep_count' => 0]);
        if (!copy(__DIR__ . '/media-observer.php', WP_CONTENT_DIR . '/mu-plugins/odbfs3-media-observer.php')) {
            throw new RuntimeException('Cannot install observer.');
        }
        echo "result=zip_installed_activated_scoped\n";
    } elseif ($mode === 'prepare-start') {
        $label = $argv[2] ?? '';
        if (!in_array($label, ['smoke', 'large'], true)) { throw new RuntimeException('Unknown fixture.'); }
        $controller = new MediaJobController();
        if ($controller->current() !== null && !$controller->current()->terminal()) { throw new RuntimeException('Job active.'); }
        $fixture = $work . '/fixtures/' . $label;
        $info = assertFixture($fixture);
        $root = WP_CONTENT_DIR . '/uploads/ziptest-' . $label;
        if (file_exists($root)) { throw new RuntimeException('Fixture destination already exists.'); }
        if (!is_dir(dirname($root))) { mkdir(dirname($root), 0755, true); }
        // Copy only synthetic data into a fresh test-site upload root.
        // Retain the independent fixture and expected hashes outside the web root.
        if (!mkdir($root, 0755)) { throw new RuntimeException('Cannot create fixture destination.'); }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture . '/uploads', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) { throw new RuntimeException('Symlink fixture rejected.'); }
            $relative = substr($file->getPathname(), strlen($fixture . '/uploads/'));
            $target = $root . '/' . $relative;
            if ($file->isDir()) { mkdir($target, 0755); }
            elseif (!$file->isFile() || !copy($file->getPathname(), $target)) { throw new RuntimeException('Fixture copy failed.'); }
        }
        update_option('upload_path', $root);
        wp_upload_dir(null, false, true);
        if (!is_dir($work . '/plans')) { mkdir($work . '/plans', 0700); }
        $started = microtime(true);
        $plan = MediaUploadPlan::prepare((new WordPressMediaSourceFactory())->create(), $work . '/plans');
        compareExpected($fixture, $plan->directory . '/inventory.jsonl');
        $metadata = $plan->metadata();
        if ($metadata['files'] !== $info['files'] || $metadata['bytes'] !== $info['bytes']) { throw new RuntimeException('Totals differ.'); }
        $job = $controller->start($plan);
        echo json_encode(['result' => 'queued_from_verified_fixture', 'id' => $job->id,
            'files' => $info['files'], 'bytes' => $info['bytes'], 'prepare_seconds' => round(microtime(true)-$started, 3)], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($mode === 'status') {
        $job = (new MediaJobController())->current();
        $events = 0; $next = null;
        foreach (_get_cron_array() as $timestamp => $hooks) {
            foreach ($hooks[MediaJobController::HOOK] ?? [] as $event) { ++$events; $next = $timestamp; }
        }
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", WordPressJobStore::OPTION_NAME));
        echo json_encode(['id' => $job?->id, 'status' => $job?->status, 'files' => $job?->processedFiles,
            'bytes' => $job?->processedBytes, 'part' => $job?->checkpoint['part'] ?? null,
            'attempts' => $job?->attempts, 'error' => $job?->errorCode,
            'cron_events' => $events, 'next_utc' => $next === null ? null : gmdate('c', $next),
            'autoload' => $autoload], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($mode === 'restore') {
        $label = $argv[2] ?? '';
        if (!in_array($label, ['smoke', 'large'], true)) { throw new RuntimeException('Unknown fixture.'); }
        $info = assertFixture($work . '/fixtures/' . $label);
        $job = (new MediaJobController())->current();
        if ($job === null || $job->status !== 'succeeded') { throw new RuntimeException('No successful job.'); }
        $state = $job->checkpoint;
        if ($state['bucket'] !== 'ceri-secure-s3-storage-test' || $state['prefix'] !== 'wordpress-test/media-cron-ziptest/') {
            throw new RuntimeException('Wrong S3 destination.');
        }
        $prefix = $state['prefix'] . 'backups/media/' . $job->id . '/';
        $client = new SecureS3StorageForWordpressVendor\Aws\S3\S3Client(['version' => 'latest', 'region' => 'ap-northeast-1', 'retries' => 0]);
        $directory = $work . '/restore-' . $label . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700); mkdir($directory . '/files', 0700);
        $marker = $directory . '/complete.json'; $manifest = $directory . '/inventory.jsonl';
        downloadObject($client, $state['bucket'], $prefix . 'complete.json', $marker);
        if (filesize($marker) > 8192) { throw new RuntimeException('Oversized marker.'); }
        $completion = json_decode(file_get_contents($marker), true, 8, JSON_THROW_ON_ERROR);
        if (($completion['format'] ?? '') !== 'odbfs3-media-complete' || ($completion['version'] ?? null) !== 1
            || ($completion['run'] ?? '') !== $job->id || ($completion['inventory'] ?? '') !== 'inventory.jsonl'
            || ($completion['files'] ?? null) !== $info['files'] || ($completion['bytes'] ?? null) !== $info['bytes']
            || ($completion['inventory_sha256'] ?? '') !== $state['metadata']['inventory_sha256']
            || ($completion['object_key_rule'] ?? '') !== 'files/sha256(UTF-8 relative path)') { throw new RuntimeException('Marker mismatch.'); }
        downloadObject($client, $state['bucket'], $prefix . 'inventory.jsonl', $manifest);
        if (!hash_equals($completion['inventory_sha256'], hash_file('sha256', $manifest))) { throw new RuntimeException('Manifest digest differs.'); }
        compareExpected($work . '/fixtures/' . $label, $manifest);
        $started = microtime(true); $count = 0;
        foreach ((new MediaManifest())->entries($manifest) as $entry) {
            $destination = $directory . '/files/' . $entry->path;
            if (!is_dir(dirname($destination))) { mkdir(dirname($destination), 0700, true); }
            downloadObject($client, $state['bucket'], $prefix . 'files/' . hash('sha256', $entry->path), $destination);
            if (filesize($destination) !== $entry->size || !hash_equals($entry->sha256, hash_file('sha256', $destination))) { throw new RuntimeException('Restored bytes differ.'); }
            if (++$count % 250 === 0) { echo 'downloaded_files=' . $count . "\n"; }
        }
        if (!(new MediaManifest())->verify(new MediaSource($directory . '/files'), $manifest, $work)->successful()) { throw new RuntimeException('Restored tree differs.'); }
        $report = ['result' => 'scoped_zip_cron_restore_passed', 'id' => $job->id, 'files' => $info['files'], 'bytes' => $info['bytes'],
            's3_prefix' => 's3://' . $state['bucket'] . '/' . $prefix, 'restore_directory' => $directory,
            'restore_seconds' => round(microtime(true)-$started,3), 'peak_bytes' => memory_get_peak_usage(true)];
        file_put_contents($directory . '/result.json', json_encode($report, JSON_THROW_ON_ERROR), LOCK_EX);
        echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } else { throw new RuntimeException('Unknown test mode.'); }
} catch (Throwable $e) {
    fwrite(STDERR, 'test_failed=' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    if (method_exists($e, 'getAwsErrorCode')) { fwrite(STDERR, 'aws_code=' . $e->getAwsErrorCode() . "\n"); }
    exit(1);
}
