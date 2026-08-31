<?php
declare(strict_types=1);
// Read-only final assertion of the passive HTTP Cron journal.
if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'odbfs3_ziptest') { exit(1); }
$id = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { exit(2); }
$stream = fopen('/var/lib/odbfs3-work/cron-observations.jsonl', 'rb');
$before = null; $previous = null; $first = null; $last = null;
$batches = 0; $peak = 0; $pids = []; $resumedPart = false;
$phases = []; $resumedPreparation = false;
while (($line = fgets($stream)) !== false) {
    $entry = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
    if ($entry['event_job_id'] !== $id) { continue; }
    if ($entry['job_id'] !== $id || $entry['sapi'] !== 'apache2handler' || $entry['doing_cron'] !== true
        || $entry['attempts'] !== 0) { throw new RuntimeException('Unexpected worker context or retry.'); }
    $peak = max($peak, $entry['peak_bytes']); $pids[$entry['pid']] = true;
    if (isset($entry['job_phase'])) { $phases[$entry['job_phase']] = true; }
    if ($entry['phase'] === 'before') {
        if ($before !== null) { throw new RuntimeException('Overlapping observed callbacks.'); }
        if ($previous !== null) {
            if ($entry['files'] !== $previous['files'] || $entry['bytes'] !== $previous['bytes']
                || $entry['part'] !== $previous['part']) { throw new RuntimeException('Checkpoint continuity differs.'); }
            if ($entry['part'] !== null && $previous['pid'] !== $entry['pid']) { $resumedPart = true; }
            foreach (['job_phase', 'prepared_files', 'queue_cursor', 'sort_cursor', 'hash_offset', 'part_offset'] as $field) {
                if (($entry[$field] ?? null) !== ($previous[$field] ?? null)) {
                    throw new RuntimeException('Preparation checkpoint continuity differs.');
                }
            }
            if (($entry['job_phase'] ?? 'upload') !== 'upload' && $previous['pid'] !== $entry['pid']) {
                $resumedPreparation = true;
            }
        }
        $before = $entry; $first ??= $entry['utc'];
    } elseif ($entry['phase'] === 'after') {
        if ($before === null || $before['pid'] !== $entry['pid'] || $entry['files'] < $before['files']
            || $entry['bytes'] < $before['bytes']) { throw new RuntimeException('Invalid callback progress.'); }
        ++$batches; $before = null; $previous = $entry; $last = $entry['utc'];
    } else { throw new RuntimeException('Unknown journal event.'); }
}
fclose($stream);
if ($before !== null || $previous === null || $previous['status'] !== 'succeeded') {
    throw new RuntimeException('Journal does not end in completed success.');
}
echo json_encode(['result' => 'http_cron_checkpoint_continuity_passed', 'id' => $id,
    'batches' => $batches, 'distinct_pids' => count($pids), 'multipart_resumed_in_different_pid' => $resumedPart,
    'observed_job_phases' => array_keys($phases),
    'preparation_resumed_in_different_pid' => $resumedPreparation,
    'first_utc' => $first, 'last_utc' => $last, 'elapsed_seconds' => strtotime($last)-strtotime($first),
    'files' => $previous['files'], 'bytes' => $previous['bytes'], 'peak_php_allocated_bytes' => $peak],
    JSON_THROW_ON_ERROR) . "\n";
