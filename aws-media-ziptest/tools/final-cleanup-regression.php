<?php
declare(strict_types=1);

use SecureS3StorageForWordpress\Aws\MediaS3Client;
use SecureS3StorageForWordpress\Backup\Job\BackupJob;
use SecureS3StorageForWordpress\Backup\Job\JobRunner;
use SecureS3StorageForWordpress\Backup\Media\MediaSource;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadPlan;
use SecureS3StorageForWordpress\Backup\Media\MediaUploadStep;
use SecureS3StorageForWordpress\WordPress\MediaJobController;
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;

function requireTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function markerEvidence(array $head): array
{
    requireTrue(empty($head['missing']) && isset($head['ContentLength']), 'Completed marker missing.');
    $modified = $head['LastModified'] ?? null;

    return [
        'length' => (string) $head['ContentLength'],
        'checksum' => (string) ($head['ChecksumSHA256'] ?? ''),
        'etag' => (string) ($head['ETag'] ?? ''),
        'last_modified' => $modified instanceof DateTimeInterface
            ? $modified->format(DATE_ATOM)
            : (string) $modified,
    ];
}

try {
    requireTrue(
        PHP_SAPI === 'cli'
        && getenv('WORDPRESS_DB_NAME') === 'odbfs3_ziptest'
        && getenv('WORDPRESS_DB_HOST') === 'db:3306',
        'Wrong environment.'
    );
    requireTrue(preg_match('/^[a-f0-9]{32}$/D', $argv[1] ?? '') === 1, 'Invalid prior Job ID.');
    requireTrue(str_starts_with($argv[2] ?? '', '/var/lib/odbfs3-work/restore-large-'), 'Invalid restore path.');

    define('DISABLE_WP_CRON', true);
    umask(0077);
    require '/var/www/html/wp-load.php';
    global $wpdb;

    requireTrue(
        defined('ODBFS3_ISOLATED_ZIPTEST')
        && ODBFS3_ISOLATED_ZIPTEST
        && $wpdb->prefix === 'ziptest_',
        'Isolation guard failed.'
    );

    $controller = new MediaJobController();
    $prior = $controller->current();
    requireTrue(
        $prior !== null
        && hash_equals($argv[1], $prior->id)
        && $prior->status === 'succeeded'
        && $prior->processedFiles === 2006
        && $prior->processedBytes === 1107367888,
        'Prior successful job differs.'
    );
    requireTrue(
        wp_next_scheduled(MediaJobController::HOOK, [$prior->id]) === false,
        'Prior job still has a Cron event.'
    );

    $restoreResult = $argv[2] . '/result.json';
    requireTrue(is_file($restoreResult) && ! is_link($restoreResult), 'Restore evidence missing.');
    $restoreDigest = hash_file('sha256', $restoreResult);
    $restored = json_decode(file_get_contents($restoreResult), true, 8, JSON_THROW_ON_ERROR);
    requireTrue(
        ($restored['id'] ?? '') === $prior->id
        && ($restored['files'] ?? 0) === 2006
        && ($restored['bytes'] ?? 0) === 1107367888,
        'Restore result differs.'
    );

    $options = get_option('secure_s3_storage_settings', []);
    requireTrue(
        ($options['region'] ?? '') === 'ap-northeast-1'
        && ($options['bucket'] ?? '') === 'ceri-secure-s3-storage-test'
        && ($options['prefix'] ?? '') === 'wordpress-test/media-cron-ziptest/'
        && ($options['backup_schedule'] ?? '') === 'disabled'
        && ($options['retention_keep_count'] ?? -1) === 0,
        'Unexpected destination or automatic settings.'
    );

    $client = MediaS3Client::create($options['region']);
    $priorMarker = $options['prefix'] . 'backups/media/' . $prior->id . '/complete.json';
    $markerBefore = markerEvidence($client->request('HeadObject', [
        'Bucket' => $options['bucket'],
        'Key' => $priorMarker,
        'ChecksumMode' => 'ENABLED',
    ], time() + 30));

    $source = '/var/lib/odbfs3-work/final-cleanup-source-' . bin2hex(random_bytes(8));
    requireTrue(mkdir($source, 0700), 'Cannot create isolated cleanup source.');
    $payload = $source . '/incomplete.bin';
    $stream = fopen($payload, 'xb');
    requireTrue($stream !== false && chmod($payload, 0600), 'Cannot create cleanup payload.');
    $chunk = str_repeat('R', 1048576);
    for ($index = 0; $index < 9; ++$index) {
        requireTrue(fwrite($stream, $chunk) === strlen($chunk), 'Short cleanup payload write.');
    }
    requireTrue(fflush($stream) && fsync($stream), 'Cannot persist cleanup payload.');
    fclose($stream);
    $payloadDigest = hash_file('sha256', $payload);

    $oldUploadPath = get_option('upload_path', null);
    try {
        update_option('upload_path', $source);
        $resolved = wp_upload_dir(null, false, true);
        requireTrue(empty($resolved['error']) && $resolved['basedir'] === $source, 'Wrong cleanup source.');
        $plan = MediaUploadPlan::prepare(new MediaSource($source), '/var/lib/odbfs3-work/plans');
        $job = $controller->start($plan);
    } finally {
        if ($oldUploadPath === null) {
            delete_option('upload_path');
        } else {
            update_option('upload_path', $oldUploadPath);
        }
        wp_upload_dir(null, false, true);
    }
    requireTrue($job->status === 'queued' && $job->id !== $prior->id, 'Dedicated cleanup job not queued.');

    $store = new WordPressJobStore($wpdb);
    $status = (new JobRunner($store))->tick($job->id, 'media', new MediaUploadStep($client));
    $observed = $store->read();
    $running = $observed === null ? null : BackupJob::decode($observed);
    requireTrue(
        $status === 'running'
        && $running !== null
        && $running->id === $job->id
        && is_string($running->checkpoint['upload_id'] ?? null)
        && is_string($running->checkpoint['upload_key'] ?? null),
        'Multipart checkpoint not recorded.'
    );
    $client->request('ListParts', [
        'Bucket' => $options['bucket'],
        'Key' => $running->checkpoint['upload_key'],
        'UploadId' => $running->checkpoint['upload_id'],
        'PartNumberMarker' => 0,
        'MaxParts' => 1000,
    ], time() + 30);

    $failed = $running->fail('step_failed');
    requireTrue($store->compareAndSwap($observed, $failed->encode()), 'Cannot mark dedicated job failed.');
    wp_clear_scheduled_hook(MediaJobController::HOOK, [$job->id]);

    $clients = 0;
    $cleanup = new MediaJobController(
        $store,
        static function (string $region) use (&$clients): MediaS3Client {
            ++$clients;
            return MediaS3Client::create($region);
        }
    );
    $first = $cleanup->cleanupFailedJob($job->id);
    requireTrue(
        $clients === 1
        && $first['state'] === 'completed'
        && $first['multipart_recorded'] === true
        && $first['multipart_aborted_or_missing'] === true
        && $first['private_work_removed_or_missing'] === true
        && $first['completed_objects_retained'] === true,
        'First cleanup result invalid.'
    );
    requireTrue(! is_dir($plan->directory), 'Private job workspace remains.');
    requireTrue(is_file($payload) && hash_equals($payloadDigest, hash_file('sha256', $payload)), 'Source changed.');

    $second = $cleanup->cleanupFailedJob($job->id);
    requireTrue($second === $first && $clients === 1, 'Repeated cleanup performed external I/O.');

    $final = $cleanup->current();
    requireTrue(
        $final !== null
        && $final->id === $job->id
        && $final->status === 'failed'
        && array_keys($final->checkpoint) === ['cleanup']
        && ! isset(
            $final->checkpoint['cleanup']['directory'],
            $final->checkpoint['cleanup']['key'],
            $final->checkpoint['cleanup']['upload_id']
        ),
        'Final cleanup record not sanitized.'
    );
    requireTrue(wp_next_scheduled(MediaJobController::HOOK, [$job->id]) === false, 'Cleanup Cron remains.');

    $markerAfter = markerEvidence($client->request('HeadObject', [
        'Bucket' => $options['bucket'],
        'Key' => $priorMarker,
        'ChecksumMode' => 'ENABLED',
    ], time() + 30));
    requireTrue($markerAfter === $markerBefore, 'Successful marker changed.');
    requireTrue(hash_equals($restoreDigest, hash_file('sha256', $restoreResult)), 'Restore evidence changed.');

    echo json_encode([
        'result' => 'final_release_cleanup_passed',
        'release' => '0.2.0',
        'prior_job' => $prior->id,
        'test_job' => $job->id,
        'multipart_recorded' => true,
        'multipart_aborted_or_missing' => true,
        'private_workspace_removed' => true,
        'dedicated_source_retained' => true,
        'second_cleanup_external_client_delta' => 0,
        'prior_completed_marker_unchanged' => true,
        'restore_evidence_unchanged' => true,
        'final_record_sanitized' => true,
        'cron_events' => 0,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'final_cleanup_failed=' . get_class($exception)
        . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . PHP_EOL
    );
    exit(1);
}
