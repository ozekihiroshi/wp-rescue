# Isolated AWS media release-ZIP test environment

This is a standalone test project, not an override for production or local 8081.
No source repository, production database, uploads or Traefik network is mounted.
Use it only on the authorized AWS test host. Do not run `down -v`, prune, or
`--remove-orphans` while retaining test evidence.

## Scope

- Compose project: `odbfs3-media-ziptest`.
- Containers: `odbfs3-media-ziptest-web`, `odbfs3-media-ziptest-db`.
- Three project-owned volumes: HTML/uploads, database, private working plans.
- Dedicated internal database network and a separate outbound AWS network.
- No external ingress: host port `127.0.0.1:18084`; Traefik disabled.
- Web memory cap 256 MiB; DB cap 192 MiB; no container swap, 0.5 CPU each.
- No automatic restart. Explicitly start/stop this project between tests.
- Reuses cached production image digests without upgrading production. Initial
  image contains WordPress 7.0 and PHP 8.3; this is not the full compatibility matrix.
- Automatic WordPress updates disabled for reproducible tests; WP-Cron enabled.
- AWS credentials are not copied or stored. The existing EC2 role may be used
  by the default provider; this network is not an IAM security boundary.

## Initialize once

Deploy this directory to `/home/ubuntu/docker/wp-rescue-media-ziptest` as ubuntu.
The directory must be newly created and have mode 0700. Run from that directory:

```sh
php tools/create-secrets.php
docker compose -f compose.yml config --quiet
docker compose -f compose.yml up -d
docker exec --user root odbfs3-media-ziptest-web \
  chown www-data:www-data /var/lib/odbfs3-work
docker exec --user www-data odbfs3-media-ziptest-web \
  chmod 700 /var/lib/odbfs3-work
docker exec --user www-data odbfs3-media-ziptest-web \
  php /opt/ziptest-tools/initialize.php
curl --fail --silent --show-error --max-time 30 \
  http://127.0.0.1:18084/wp-cron.php
```

Passwords are generated only on the server under `private/` (excluded from Git).
Never paste their contents into chat, logs or commands. The host private directory
is mode 0700; individual read-only secret mounts support the container UIDs.
Do not regenerate these credentials after DB initialization.

## Browser access

From the local PC, keep this SSH tunnel open:

```sh
ssh -N -L 127.0.0.1:18084:127.0.0.1:18084 community
```

Open `http://127.0.0.1:18084/wp-admin/`. User: `ziptest_admin`.
The admin UI test moved this environment from 8084 to 18084 to avoid the
local Moodle release-test listener. Older acceptance reports retain their
historical 8084 addresses; use 18084 for current commands. Both Apache and the
WordPress URL use 18084 so internal HTTP Cron also continues to work.
Retrieve the password privately from the server's `private/admin-password` file
when needed; do not send it to the assistant. No public hostname is configured.

## Before media testing

Install only a newly built, hashed development distribution ZIP; do not use an
old public 0.1.1 ZIP or mount development source. Keep the ZIP and its source
commit in a private `artifacts/` directory. Do not publish this development build.
Set the authorized bucket and a distinct prefix under `wordpress-test/`, with
database automatic backup and retention disabled. Use synthetic media only.
Prepare plans under `/var/lib/odbfs3-work`, never in public uploads or `/tmp`.

The test-only MU plugin `odbfs3-ziptest-cron-probe.php` checks real HTTP Cron
dispatch via a one-shot event and saves a result to the test DB. This probe is
not included in any distribution ZIP and does not test the media worker itself.

To stop resource use without deleting data:

```sh
docker compose -f compose.yml stop
```

ZIP installation, S3 transfer, media-job persistence, recovery and restore
verification are separate acceptance checks after this environment is ready.
The latest completion-paced Cron and independent 1 GiB restore result is in
[`CRON-OPTIMIZATION-TEST.md`](CRON-OPTIMIZATION-TEST.md).
