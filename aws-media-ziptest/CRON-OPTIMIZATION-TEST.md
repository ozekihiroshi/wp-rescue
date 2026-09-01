# Completion-paced media Cron acceptance — 2026-09-01

The isolated AWS ZIP-test stack passed the completion-paced single-event Cron
regression and independent restore for plugin commit `adc1a76`.

## Installed artifact

- SHA-256:
  `b6d1fdebcbf73924e31bbc94194213880567f7106878fe627fffe36597ab725c`.
- 3,861 installed files matched the scoped ZIP with no extras.
- The prior artifact was preserved as
  `preparation-batches-before-adc1a76-ba3a40e7.zip` before the guarded update.
- The prior terminal job, settings, fixture roots, and restore evidence were
  preserved. No production stack, public release, or WordPress.org SVN changed.

## Result

- Job: `5810eb44b5883b0b5cc42aded474cce4`.
- Fixture: 2,006 files / 1,107,367,888 bytes, including one 1 GiB file.
- Dispatch: real HTTP WordPress Cron only; no direct worker tick or event-time
  modification.
- Journal: 21 callbacks, two PHP PIDs, continuous checkpoints, successful
  preparation resume across PIDs, final attempts zero and no remaining event.
- First/last callback span: 536 seconds (previous comparable run: 1,858).
- Approximate callback time: 315 seconds; between-callback time: 221 seconds
  (previous: 305 / 1,553).
- Final status: succeeded; all 2,006 files and 1,107,367,888 bytes verified in S3.

Independent restore also passed for every path, size, and SHA-256. The fresh
private restore is `/var/lib/odbfs3-work/restore-large-bf077253b9970865`, mode
0700, with a 0600 result record, exactly 2,006 regular files, no symbolic links,
and 115.994 seconds download/verification time.

Evidence remains intentionally retained. About 22 GiB was free after the test;
cleanup requires a separate explicit decision.
