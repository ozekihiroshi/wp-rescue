<?php
// Test-only observer. Does not change scheduling, job state, or S3 requests.
if (!defined('ODBFS3_ISOLATED_ZIPTEST') || !ODBFS3_ISOLATED_ZIPTEST) { return; }
foreach ([1 => 'before', 20 => 'after'] as $priority => $phase) {
    add_action('secure_s3_storage_media_worker', static function ($id) use ($phase): void {
        $job = (new SecureS3StorageForWordpress\WordPress\MediaJobController())->current();
        $record = ['utc' => gmdate('c'), 'phase' => $phase, 'pid' => getmypid(),
            'sapi' => PHP_SAPI, 'doing_cron' => defined('DOING_CRON') && DOING_CRON,
            'event_job_id' => $id, 'job_id' => $job?->id, 'status' => $job?->status,
            'files' => $job?->processedFiles, 'bytes' => $job?->processedBytes,
            'part' => $job?->checkpoint['part'] ?? null,
            'job_phase' => $job?->checkpoint['phase'] ?? 'upload',
            'prepared_files' => $job?->checkpoint['files'] ?? null,
            'queue_cursor' => $job?->checkpoint['queue_cursor'] ?? null,
            'sort_cursor' => $job?->checkpoint['sort_cursor'] ?? null,
            'hash_offset' => $job?->checkpoint['hash_offset'] ?? null,
            'part_offset' => $job?->checkpoint['part_offset'] ?? null,
            'attempts' => $job?->attempts, 'peak_bytes' => memory_get_peak_usage(true)];
        $previous = umask(0077);
        file_put_contents('/var/lib/odbfs3-work/cron-observations.jsonl',
            json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        umask($previous);
    }, $priority, 1);
}
