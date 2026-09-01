# Process-kill and concurrent-worker acceptance test

## Result

Passed on 2026-09-01 in the isolated AWS ZIP-test environment.

- Plugin commit: `81a6d3d` (`Add explicit failed media job cleanup`).
- Environment baseline commit: `6d84cf8`.
- Container: `odbfs3-media-ziptest-web`.
- Database/prefix isolation: `odbfs3_ziptest` / `ziptest_`.
- Media source: a separately copied 1 GiB synthetic file; the preserved fixture
  was read-only and did not share an inode with the test source.
- Final test job: `9a0ff6019d93b226237d7d89716b41b2`.

The test demonstrated both fences around a real stopped process:

1. A test-only `JobStep` used the production `JobRunner` to persist a real
   60-second lease while holding the real preparation `worker.lock`.
2. The holder persisted its PID and checkpoint digest, then stopped itself with
   Linux `SIGSTOP`. Docker reported the exact PHP process as `STAT=Ts`.
3. A production `MediaJobController::run()` call returned `busy` while the lock
   was held. The before/after job state was identical.
4. After verifying the exact stopped command, only container PID `26414` was
   sent `SIGKILL`. Docker then showed that the holder process was absent.
5. A second production controller call immediately after the kill also returned
   `busy`. The OS had released the lock, so this was the persisted, unexpired
   lease fence. Phase, attempts, offsets and progress remained identical.
6. The lease was allowed to expire by wall-clock time; no DB timestamp or job
   record was edited. A production controller call then resumed the same
   checkpoint and advanced from `file_hash` to `parts`.

## Durable evidence

The stopped holder recorded:

- lease until: `1788230215`;
- lease token present: yes;
- checkpoint SHA-256:
  `25a174cb0b76dc22f65262f612d78c29c89849fc97f876f71742368e0d49feba`.

Both blocked calls had the same state before and after:

- status: `running`;
- phase: `file_hash`;
- attempts: `2`;
- hash offset: unset;
- processed files/bytes: `0` / `0`;
- multipart upload ID: absent.

After natural expiry, the recovery call changed the durable state to:

- phase: `parts`;
- hash offset/file size: `1073741824` / `1073741824`;
- lease absent;
- attempts reset to `0`;
- processed files/bytes remained `0` / `0` because preparation was not yet
  complete.

This proves that the killed holder did not select a partial checkpoint, another
worker could not mutate the job before expiry, and the replacement worker could
resume from the last committed checkpoint.

## Safe termination and cleanup

After recovery was proven, only the dedicated copied source's timestamp was
changed. The existing source-identity checks failed the job closed with
`step_failed`. Explicit cleanup then removed only that job's private workspace.
It recorded:

- multipart recorded: no;
- private work removed or missing: yes;
- completed objects retained: yes;
- exact run prefix:
  `wordpress-test/media-cron-ziptest/backups/media/9a0ff6019d93b226237d7d89716b41b2/`.

Running the same cleanup a second time returned the identical completed result,
demonstrating idempotence. The dedicated source was intentionally retained as
test evidence. Its contents still matched the preserved fixture:

`65b7f1c1b1275903c51e2d47b08c6a91156763acac2d49412627034c09a9da6d`

for both 1 GiB files.

## Cron isolation and restoration

Ordinary HTTP WP-Cron was temporarily disabled in the isolated container so its
15-second health check could not race the controlled workers. The exact original
`wp-config.php` was saved privately, restored atomically after the test and
verified byte-for-byte by SHA-256:

`8bcccc303852c7b0c19edb3983e796601e23dc2e216947f473cda3cac48fc2b2`

Normal WP-Cron is enabled again. `upload_path` was restored, the final job has no
lease or scheduled media event, and the plugin cleanup is complete. Existing
successful backups, the 2,006-file fixture, prior restore evidence and prior
cleanup evidence were not cleanup targets.

## Aborted preliminary attempts

Two preliminary runs are preserved rather than rewritten as successes:

- `24f82801702ec86b87c0807bc23b58e5` completed normally because HTTP WP-Cron
  advanced it while the first controller was being observed. Its completed
  object was not deleted.
- `1131d28ff328865ed0bc52bb02b05699` failed safely because the test helper used
  an unavailable PHP signal constant before sending a signal. Its job-specific
  workspace was explicitly cleaned twice and the idempotent results matched.

Neither preliminary issue was a plugin-runtime failure. The final run used the
Linux signal number after verifying the POSIX extension and isolated Cron before
claiming the lease.
