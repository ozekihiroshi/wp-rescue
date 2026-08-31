# AWS environment verification — 2026-08-31

Status: **isolated environment ready**, not media-feature acceptance.

- Host: `ip-172-31-2-103` (`community` SSH alias).
- Deployment: `/home/ubuntu/docker/wp-rescue-media-ziptest`.
- Project: `odbfs3-media-ziptest`.
- Web/DB: healthy, zero restarts, no OOM kills at verification.
- WordPress 7.0, PHP 8.3.31, MariaDB image digest pinned in Compose.
- Test DB `odbfs3_ziptest`, host `db:3306`, prefix `ziptest_`.
- No active plugins yet. Distribution ZIP installation is the next step.
- Web listens at host `127.0.0.1:8084` only; no Traefik routing.
- Login and `wp-cron.php` both returned HTTP 200.
- One-shot test MU-plugin Cron callback recorded `doing_cron=true`,
  `sapi=apache2handler`, at `2026-08-31T01:50:28+00:00` in the test DB.
  Its scheduled event was removed after execution. This was a real HTTP
  WordPress Cron probe, **not** an invocation of the media worker.
- Private persistent plan volume `/var/lib/odbfs3-work`: writable by www-data,
  mode 0700, outside the web root.
- Separate HTML, DB and work named volumes. No source-code bind mount.
- No long-lived AWS access key, secret key or session token was copied into
  the test container environment. Actual role-provider/S3 access remains to test.
- Generated DB and admin passwords remain only on the AWS host under the
  private deployment directory. No values were printed or copied locally.
- Observed memory: web 70.89 MiB / 256 MiB; DB 70.83 MiB / 192 MiB.
  Startup can cause host swap activity; this is a resource-constrained test host.

Production containers retained their original image IDs and start times:

- `wp-rescue`: `2026-08-23T10:17:52.190929068Z`.
- `wp-rescue-db`: `2026-08-23T10:17:51.866225961Z`.

No production Compose, plugin, settings, database, media, IAM policy, lifecycle,
or Traefik configuration was changed. The original large fixture and prior S3
test objects were not modified. No Docker images were pulled or production
containers recreated.

The isolated containers are left running for the next ZIP test. They do not
restart automatically. Stop only this Compose project when it is not needed;
do not remove its volumes until test evidence is no longer needed.

Next: build/hash the current development release ZIP, install it here, configure
the authorized test S3 destination, copy only synthetic fixtures, then validate
the real plugin job store, Cron worker, continuation and full restore checksums.
