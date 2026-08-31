# Distribution ZIP / real WordPress Cron fixture test

Use only the isolated project described in README.md. The helpers reject other
DB names/hosts. No helper calls `MediaJobController::run()` or `JobRunner::tick()`.
Submission uses the shipped controller and real `WordPressJobStore`; processing
uses HTTP `wp-cron.php` and the plugin's ordinary 60-second schedule.

## Artifact provenance

Base commit: `5e706f733e3a0805ed690b3064787079c87700c5`.

The first build exposed a genuine distribution-only defect: PHP-Scoper prefixed
the WordPress-owned `wpdb` constructor type, rejecting the actual DB object.
The original ZIP SHA-256 was
`fb56c049a1715e7e0f51bfc2544ec9694eb9b0c894c7671346ca102c47447b79`.
No media job or S3 upload was started from that defective ZIP.

The fix adds `wpdb` to `exclude-classes` in `scoper.inc.php` and a reflection
assertion to the build-time `tests/manual/test-scoped-release.php`. The new
assertion fails against the original ZIP and passes against the fixed ZIP.
The tested source is the base commit **plus these two local file changes**,
not the unchanged GitHub commit. Commit/push and CI are a separate next action.

Fixed ZIP SHA-256:
`087366cad0358791dcc81ef7961eb67366d34700f320fba316ccfb637ab7ea1e`.

The header still says 0.1.1 because development versioning has not been finalized.
**Neither test ZIP is the public 0.1.1 release. Do not publish/replace a release.**
The public release ZIP was not overwritten. The fixed local artifact lives in
`secure-s3-storage-for-wordpress/build/aws-cron-wpdbfix-5e706f7/`.

## Helper roles

- `media-test.php install SHA256`: fresh WordPress ZIP installation and activation;
  checks SDK scoping, sets the isolated S3 destination, disables DB schedules and
  retention, and installs the test-only passive Cron observer.
- `update-zip.php SHA256`: replaces only the isolated test plugin using WordPress's
  ZIP overwrite API. Refuses if a job already exists. Recheck in a fresh process.
- `media-test.php prepare-start smoke|large`: validates the private synthetic
  fixture and independently recorded expected hashes, copies files to a new
  test-site upload root, prepares a persistent plan, and submits the job.
- `media-test.php status`: reads actual DB-backed status and scheduled event count;
  never ticks the worker. CLI spawning is disabled only in this helper process.
- `watch-cron.sh JOB_ID`: sends HTTP requests to the loopback-only test site, reads
  status every 30 seconds, and stops on terminal state or a bounded timeout. It
  does not change the one-minute schedule or accelerate event timestamps.
- `media-test.php restore smoke|large`: requires a succeeded job and matching
  completion marker; downloads into a fresh empty private directory, matches the
  downloaded manifest against independent fixture hashes, checks every file,
  and independently rescans the restored tree.
- `media-observer.php`: test-only MU plugin; records before/after event timestamps,
  PID, HTTP Cron context, counters and PHP allocated memory. Does not change job
  state, scheduling or S3 calls. Raw SDK requests/credentials are never logged.

Example commands on AWS (only after the matching verified ZIP and fixtures have
been copied into this test environment):

```sh
docker exec --user www-data odbfs3-media-ziptest-web \
  php /opt/ziptest-tools/media-test.php prepare-start smoke
docker exec --user www-data odbfs3-media-ziptest-web \
  php /opt/ziptest-tools/media-test.php status
curl --fail --silent --show-error --max-time 35 \
  http://127.0.0.1:8084/wp-cron.php
docker exec --user www-data odbfs3-media-ziptest-web \
  php /opt/ziptest-tools/media-test.php restore smoke
```

Do not repeat preparation into an existing test upload root. Helpers preserve
existing data and do not reset failed jobs. A failed multipart run requires
separate, precise cleanup of its saved upload ID; never delete a broad prefix.

## Boundaries

Destination: `ceri-secure-s3-storage-test`, region `ap-northeast-1`, prefix
`wordpress-test/media-cron-ziptest/`. Each job has its own random run subprefix.
No IAM, lifecycle, bucket policy, production WordPress, or local 8081 changes.
AWS credentials use the EC2 role through the scoped SDK default provider.

Only private copies of the prior synthetic fixtures are used. Their originals
remain under `/tmp/odbfs3-media-test.1tl1sbb4` in the production container, read
only for copying. No production uploads or DB records are copied or restored.
No WordPress attachment registration is performed by this test.

Site HTML/uploads, DB job options, plans, fixture copies, observation journal and
restored files persist in dedicated Docker volumes. Test S3 backups are retained
and remain billable. Stop only this project when not needed; do not delete its
volumes or S3 objects without selecting the exact test artifacts for cleanup.

This verifies transport and background execution, not a released media UI,
background preparation, forced-kill/concurrent-worker recovery, KMS policy,
media retention, or matching database/attachment restoration. It does not replace
the full compatibility matrix or a fresh CI run after committing the fix.
