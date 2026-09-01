# Ozeki Database Backup for S3 0.2.0 final release regression

## Result

Passed on 2026-09-01 using the isolated AWS ZIP-test environment. No
production WordPress container, database, uploads, Traefik route, public
GitHub release or WordPress.org SVN path was changed.

## Release artifact and CI

- Plugin commit: `aeaf6b9` (`Prepare media backup release 0.2.0`).
- Artifact: `ozeki-database-backup-for-s3-0.2.0.zip`.
- SHA-256:
  `589d8c3c38f168e51d011f575b290dd0f70138a9be9d4a79059290ca19d70935`.
- Size: 8,203,878 bytes; 3,862 files.
- All installed files matched the scoped ZIP with no extra files.
- Backup Job Tests, Media Upload Tests, Media Inventory Tests and Plugin Check
  all passed for `aeaf6b9`. Plugin Check reported no errors.

## Local 8082 fresh-install regression

The old plugin was removed only from the volume-backed 8082 ZIP-test site. The
development source repository was not mounted or deleted. Version 0.2.0 was
installed from the exact release ZIP and activated.

- All 3,862 installed files matched the ZIP.
- Fresh settings were absent and media status was `missing`.
- Deactivation and reactivation succeeded.
- No database or media Cron event remained with automatic backup disabled.
- The plugin `uninstall.php` removed settings as expected. WP-CLI `plugin
  delete` was separately confirmed to delete files without running uninstall.

## Isolated AWS media regression

- S3 read/write/delete connection test passed.
- Job: `422e4ad2eadb85fe14df5311ed6d921d`.
- Synthetic source: 2,006 files / 1,107,367,888 bytes, including one 1 GiB
  file.
- Dispatch: real HTTP WordPress Cron only; no direct worker tick or Cron
  timestamp modification.
- Result: succeeded; attempts zero; remaining media Cron events zero.
- Passive journal: 20 callbacks, two PHP PIDs, preparation resumed in a
  different PID, 500 seconds between first and final observed callbacks.
- Peak PHP allocated memory: 20,975,616 bytes.
- S3 prefix:
  `s3://ceri-secure-s3-storage-test/wordpress-test/media-cron-ziptest/backups/media/422e4ad2eadb85fe14df5311ed6d921d/`.
- Independent restore:
  `/var/lib/odbfs3-work/restore-large-1f4f0e7a21d55f35`.
- Restore took 104.415 seconds. Completion marker, inventory, every path,
  size and SHA-256, and the restored tree all matched the independent fixture.

## Explicit failed-job cleanup regression

A separate private 9 MiB synthetic source created a new incomplete multipart
upload. Only that job was marked failed and explicitly cleaned.

- Failed test job: `fda09ab6e3eb8c91aca99e7b34aaaf8a`.
- Multipart upload was durably recorded and then aborted or already absent.
- The exact private generated workspace was removed.
- The dedicated source remained unchanged.
- Repeated cleanup performed no additional external I/O.
- The final durable record was sanitized and retained no path, object key or
  multipart upload ID.
- The prior successful completion marker and independent restore evidence were
  unchanged.
- Remaining media Cron events: zero.

## Isolated AWS database regression

The user explicitly authorized export of the isolated `odbfs3_ziptest`
database to the configured test prefix.

- Backend: native.
- S3 object:
  `s3://ceri-secure-s3-storage-test/wordpress-test/media-cron-ziptest/backups/database/2026/09/01/db-odbfs3_ziptest-20260901-040807.sql.gz`.
- Gzip size: 25,376 bytes.
- Gzip SHA-256:
  `a5088599cb76ea6c4bdee4601e149f6f8ec3003aec7597613cecf96515cfbfb1`.
- Expanded SQL size: 134,113 bytes.
- SQL SHA-256:
  `9079837b2d1fcf3eeb0d7466a3ad1f6b095c37b08c867700f6cf1bee7162fca0`.
- S3 download Content-Length, gzip magic and MariaDB dump structure matched.
- Empty restore database: `odbfs3_restore_20260901_040807`.
- All 12 restored tables passed `CHECK TABLE`.
- Eleven non-dynamic table checksums matched the current source database.
- `ziptest_options` was handled separately because successful backup history
  is recorded after dump creation. Its plugin settings row checksum matched.

## Publication boundary

The 0.2.0 candidate passed its release gate. Publishing to GitHub Releases and
WordPress.org SVN remains a separate explicit release action.
