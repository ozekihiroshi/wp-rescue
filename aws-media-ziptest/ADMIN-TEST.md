# AWS admin UI readiness — 2026-08-31

## Installed development artifact

- Plugin commit: `71a511e87429e1f3b2a592cda9ff79780e3d67b4`.
- All four plugin CI workflows passed before deployment.
- ZIP SHA-256: `d2be0c939903d550ef73c525151b0ff755918e12795fa681c761f57eb1d4b682`.
- Server artifact: `/var/lib/odbfs3-work/artifacts/admin-ui-71a511e.zip`.
- All 3,861 installed files match the ZIP, with no additional runtime files.
- This still has the development 0.1.1 header. The public release and SVN tag
  were not changed and must not be overwritten with this development package.

## Configuration and access

The existing work volume is reused. `ODBFS3_MEDIA_WORK_DIR` points to
`/var/lib/odbfs3-work/plans`, owned by www-data (33:33), mode 0700. The plugin's
private-storage checks and installed-code admin rendering passed; start is enabled.

The local PC's 8084 is occupied by `moodle-rescue-release`. Moodle was not
stopped or reconfigured. The AWS test stack's WordPress URL, Apache listener,
Docker loopback publication, healthcheck and Cron watcher now consistently use
**18084**. Internal HTTP Cron and the login endpoint both returned 200; the web
container was healthy. Historical reports retain their original 8084 addresses.

Use the SSH tunnel documented in README.md and visit:

`http://127.0.0.1:18084/wp-admin/options-general.php?page=ozeki-database-backup-for-s3`

The test username is `ziptest_admin`. Retrieve its existing password privately
from the AWS deployment's `private/admin-password` when required. Do not put it
in chat, shell arguments, logs, or this repository. No password was reset or
retrieved by the deployment helper.

## Confirmed boundaries

- Only the isolated test web container was recreated; production web/DB and
  the isolated DB retained their IDs and start times.
- Settings, active plugins, the media job, upload_path and Cron were preserved
  during the ZIP overwrite. No uninstall or source mounting was used.
- S3 read/write/delete test passed using the installed plugin and its existing
  default credential provider, restricted by the helper to bucket
  `ceri-secure-s3-storage-test`, prefix `wordpress-test/media-cron-ziptest/`.
  Only the connection test's random temporary object was created/deleted.
- No IAM changes, credential copies, production data writes or media cleanup.
- Pre-update plugin archive and Compose backups are retained privately on AWS.
- `tools/admin-readiness.php` supports inspect/update/verify/connect only. It
  refuses active jobs and unexpected environment/destination settings. It never
  enqueues or executes a media worker, and does not emit raw exceptions/secrets.

## Manual acceptance still pending

The Browser skill could not start because of the PC's deny-read ACL helper
error. Authenticated UI start, polling, stale-form behavior, and the new run's
upload/restore have **not** been verified in this step. CLI rendering and the
connection test do not establish those browser results.

The visible succeeded job is the PREVIOUS test:
`67cae9db3bf81d1b4fd9afad260f45e6`, 2,006 files, 1,107,367,888 bytes. It has no
remaining media Cron events. Its existing synthetic uploads tree remains
selected; starting now uses that roughly 1 GiB fixture, not production uploads.

Log in, check Test Connection, then use Start Media Backup **once** and record
the new Job ID. Expect queued/running status and changing preparation/upload
counters; the old succeeded ID is not proof of the new test. Do not upload or
modify files while this run is active. Follow its HTTP Cron and independently
verify restored paths, sizes and SHA-256 after success before declaring acceptance.

## Authenticated UI run and independent restore — 2026-09-01

- The administrator started one media job from the settings page. New Job ID:
  `5c9b6f36dd095ee0ee0eefff1456175f`.
- UI progress showed queued/listing, 2,006 prepared checksums, upload progress,
  and finally succeeded with 2,006 files / 1.03 GB.
- Server result: 2,006 files / 1,107,367,888 bytes, attempts 0, error empty,
  no remaining media Cron event.
- HTTP Cron journal continuity passed across 32 callbacks and 4 PHP PIDs.
  Elapsed time was 1,858 seconds; approximately 305 seconds were inside callbacks
  and 1,553 seconds were between them. This makes Cron waiting, especially for
  many small per-file upload-and-verify operations, the main performance issue.
- Independent restore downloaded the completion marker and inventory, verified
  the inventory digest against the trusted job state and original synthetic
  fixture, downloaded every object to a fresh private directory, verified each
  path/size/SHA-256, and rescanned the completed tree successfully.
- Restore result: 2,006 files / 1,107,367,888 bytes in 123.986 seconds; PHP peak
  allocated memory 59,244,544 bytes. Fresh directory:
  `/var/lib/odbfs3-work/restore-large-a717ca5323a8e433`.
- Restore directory is 0700 and result.json 0600, both www-data-owned. The tree
  contains 2,006 regular files and no symbolic links. About 24 GiB remained.
- Restore used GET operations and did not overwrite original media or modify S3.
  The job remains succeeded with zero events. Restored evidence is intentionally
  retained until a later, explicit cleanup decision.

This completes the admin-start-to-independent-restore acceptance path for the
development ZIP. Performance tuning should preserve the bounded callback,
checkpoint, retry and source-change guarantees and be tested as a separate run.
