# Explicit failed media cleanup acceptance — 2026-09-01

The isolated AWS release-ZIP environment passed the explicit failed-job cleanup
acceptance test for plugin commit `81a6d3d`. This test did not target the prior
successful job, production WordPress, the public 0.1.1 release or WordPress.org
SVN.

## Commit, CI and installed artifact

- Plugin commit: `81a6d3d` (`Add explicit failed media job cleanup`).
- All four GitHub Actions workflows passed:
  - Backup Job Tests: run `33459216844`.
  - Media Upload Tests: run `33459216876`.
  - Media Inventory Tests: run `33459216784`.
  - Plugin Check: run `33459216799`.
- Development ZIP SHA-256:
  `8ed216c0e6ad343b3c42f6c255515bd2d079f5b04b884893679365c258f033c2`.
- All 3,862 installed files matched the scoped ZIP with no extra runtime files.
- The previous installed artifact was retained as
  `preparation-batches-before-cleanup-81a6d3d-b6d1fdeb.zip`.

## Safety baseline

Before the test, the current job was the known successful completion-paced run:

- Job: `5810eb44b5883b0b5cc42aded474cce4`.
- Files: 2,006.
- Bytes: 1,107,367,888.
- Remaining media Cron events: zero.
- Its S3 `complete.json`, private fixture and independently restored tree existed.

The test used only the isolated `odbfs3-media-ziptest-web` container, test DB,
private work volume and `wordpress-test/media-cron-ziptest/` prefix. Automatic
database backup and retention remained disabled. No IAM, lifecycle, bucket
policy, production stack, public release or SVN change was made.

## Deliberate failed job

A separate private 9 MiB synthetic source and generated upload plan were used.
The worker executed only the first bounded upload step so S3 created a multipart
upload and the exact upload ID/key were durably checkpointed. `ListParts`
succeeded before the job was deliberately transitioned to `failed`.

- Dedicated failed Job: `7bf18875ea0ff84a4ed0a35b29032ea4`.
- The prior successful result was archived, not deleted.
- Its scheduled worker event was cleared before cleanup.

The explicit cleanup was then invoked with that exact failed Job ID. S3
acknowledged `AbortMultipartUpload`, the exact private generated workspace was
removed, and the separate synthetic source was retained.

## Results

- Final job status remains `failed`; cleanup state is `completed`.
- `multipart_recorded`: true.
- `multipart_aborted_or_missing`: true.
- `private_work_removed_or_missing`: true.
- `completed_objects_retained`: true.
- The durable record is sanitized: no directory, object key or upload ID remains.
- A second plugin cleanup returned the same result without constructing an S3
  client (`external client delta: 0`).
- No media Cron event remains.
- The prior succeeded job archive is unchanged.
- The prior S3 completion marker remains present.
- The retained fixture and restore were independently reread after cleanup:
  all 2,006 paths, sizes and full SHA-256 values matched, totaling
  1,107,367,888 bytes.
- The dedicated 9 MiB source remains private as evidence that cleanup did not
  cross from generated work into source data.
- The machine-readable result is retained privately on AWS as
  `/var/lib/odbfs3-work/artifacts/cleanup-acceptance-81a6d3d-result.json`
  with mode 0600.

The first acceptance harness incorrectly required a repeated S3 abort to return
404. S3 may acknowledge an already absent multipart with 204, so that assertion
produced a false-negative after the plugin cleanup had already completed. A safe
diagnostic confirmed the sanitized completed record. The corrected verifier
accepts the service's idempotent abort semantics and uses the plugin's second
cleanup call to prove the stronger requirement: completed cleanup performs no
external I/O.

This establishes the approved boundary: no automatic cleanup, exact failed Job
ID only, exact recorded multipart and private work only, no completed-object or
evidence deletion, and safe repeated execution.
