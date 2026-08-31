# Distribution ZIP / background preparation acceptance test

## Follow-up 2026-08-31: preparation batching retest passed

Plugin commit `5d8dd7a24ddcd97993b4e09f556fb306ce9ff632`, helper commit
`f454b4d02f99ca7a2aa6d26e82cf93a20dd3c251`; all six CI workflows green.
Development ZIP SHA-256:
`ba3a40e7c70ff139f6dac88aa02cc30fef0b29ab65dcb676a7878b54c6000d18`.
All 3,858 installed files matched. Normal year/month initialization occurred
before enqueue; the backup's directory-change checks were unchanged.
Small job `532a4ec3c4b8db2599cad695556a17e0`: 31 files, one HTTP callback, 6 s.
Large job `67cae9db3bf81d1b4fd9afad260f45e6`: 2,006 files, 1,107,367,888 bytes,
preparation approximately 10 min 8 s, complete upload 32 min 2 s / 33 callbacks.
Both independently restored and hash-matched. Ordinary one-minute Cron only.
Full report in the sibling plugin repo:
`docs/aws-preparation-batches-test-2026-08-31.md`. Prior failed evidence remains.

## 2026-08-31 outcome: not a full acceptance pass

The smoke run (`5b6fabd7807933da730fd76771e1fe85`, 31 files) passed preparation,
HTTP Cron upload and independent restoration verification.

The large run (`821f843f1a07dd2811a66a56d7afe6a3`, 2,006 files) prepared every
file but failed closed after 83 min 3 s at final root-directory validation.
A new `2026/08` directory tree had appeared in the fresh synthetic upload root.
The exact creating callback was not instrumented. No ready marker, S3 objects
or scheduled media event remained for this failed job. Its state and files are
preserved; do not reset or edit the checkpoint to bypass validation.

Before another full run, address normal WordPress year/month directory setup
before enqueue, and the operational behavior when live uploads change. Also
evaluate the 100-step cap: small-file preparation advanced about 25 files/minute
while each callback was typically active for only 1–2 seconds. Do not silently
accelerate test Cron or weaken validation. No plugin-runtime fix was made in
this test. Full results and artifact provenance are in the sibling plugin repo:
`docs/aws-background-preparation-test-2026-08-31.md`.

This extends `MEDIA-TEST.md`: the earlier acceptance run prepared its plan
synchronously. This run submits an **unprepared** synthetic upload root and uses
the distribution's real WordPress DB store and ordinary HTTP WP-Cron for both
preparation and upload. Never run these helpers on production.

## Tested development artifact

- Plugin source: `9deffc6079615db7e55c0c949628816e661d5b61`.
- ZIP: `ozeki-database-backup-for-s3-0.1.1.zip`.
- SHA-256: `4e8d7e1d16e53cb617044739e2d42aa80df0654edce4c1b2e33e3783e2e52cad`.
- Built from a clean `git archive` of that commit, not the working tree.
- Header is still development 0.1.1; **do not replace the public 0.1.1 release**.
- The preceding run used `preparation-9deffc6.zip`; preserve that old artifact.
- The next run uses `preparation-batches.zip` with an explicitly supplied SHA-256
  and a fresh build from the new committed source. Record its provenance separately.
- The helper now initializes the normal WordPress year/month path before enqueue.
  Backup runtime remains read-only on the source, and later directory changes
  must still fail. Preparation allows 1,000 steps, upload at most 100, within
  the same cooperative 20-second callback budget. Cron remains once per minute.

Copy the completed ZIP to the isolated web container's private path
`/var/lib/odbfs3-work/artifacts/preparation-batches.zip` as www-data, mode 0600.
Wait for transfer completion and compare SHA-256 **before** installing. Preserve
old artifacts, plans, job results, fixture roots and restored directories.

## Guarded sequence on the AWS test host

Only `odbfs3-media-ziptest-web`, DB `odbfs3_ziptest`, prefix `ziptest_`, and the
loopback-only test site on port 8084 are in scope. Helpers reject other DB
names/hosts and require the isolated-environment flag. No production or local
8081 source mounts may be modified.

1. `background-test.php status`: confirm no active job or pending old event.
2. `background-test.php update SHA256`: overwrite only this test plugin via
   WordPress's ZIP upgrader, preserving terminal job history.
3. In a **new PHP process**, `background-test.php verify SHA256`: activate if
   needed, verify scoped runtime and every installed byte against the ZIP, reject
   extra files, install the passive observer.
4. `background-test.php enqueue smoke`: copy and independently hash-check only
   synthetic inputs in a fresh upload root; submit with `enqueuePreparation()`.
   Assert phase `enumerate`, with no paths list or ready marker yet.
5. `watch-cron.sh JOB_ID`: wait through normal one-minute events; never call
   `run()`/`tick()` directly, edit event timestamps or accelerate schedules.
6. `check-cron-observations.php JOB_ID`: require HTTP Cron context, successful
   terminal callback and continuity of upload and preparation checkpoint fields.
7. `media-test.php restore smoke`: require completion marker, download into a new
   private directory, match manifest to independent original fixture hashes,
   verify every restored file and rescan for unexpected/missing files.
8. Repeat steps 4–7 with `large` (2,006 files; one 1 GiB file).

PHP commands above run as:

```sh
docker exec --user www-data odbfs3-media-ziptest-web \
  php /opt/ziptest-tools/background-test.php status
```

The shell watcher runs on the AWS host. It samples every 30 seconds and permits
360 samples; a timeout stops observation, **not the WordPress job**. Inspect the
saved state before taking any further action. Preparation of many small files can
take substantially longer than a single CLI command because each Cron callback
has a bounded step count. A giant single directory may require the documented
CLI preparation path; it must not silently produce a partial successful backup.

## Safety and observations

- S3 destination is fixed to `ceri-secure-s3-storage-test`, `ap-northeast-1`,
  `wordpress-test/media-cron-ziptest/`, with a separate random prefix per job.
- DB automatic backup and retention must be disabled. No S3 cleanup occurs.
- Credentials remain the existing EC2 default-provider credentials; never copy
  or print keys, session tokens or raw signed requests.
- The passive observer logs phase, PID, counters, offsets and allocated PHP
  memory, not private serialized hash state or media contents.
- While preparation runs, inspect `autoload=no`, private workspace 0700 and
  checkpoint files 0600. The ready marker and remote completion marker must not
  represent success until their respective preparation/upload stages complete.
- Record container OOM/restart state and production start times before/after.
- Preserve S3 and local test evidence until a separately scoped cleanup is agreed.
- A successful file restore is not a DB/attachment restore, full site restore,
  forced-crash/concurrency test, compatibility matrix, UI test or public release.
