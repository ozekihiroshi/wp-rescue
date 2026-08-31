# WP Rescue

[日本語](README-jp.md)

WP Rescue is a Docker Compose foundation for a self-hosted WordPress site with MariaDB. It keeps the database off the public proxy network and provides separate local source-mount and clean release-ZIP test environments.

> **Alpha:** this is a deployment foundation, not a managed service or a security guarantee. Operators remain responsible for DNS, TLS, updates, WordPress hardening, backups, restore tests, monitoring, and incident response.

## Environments

| Compose file | Purpose | Host exposure |
| --- | --- | --- |
| `docker-compose.yml` | Production behind an external Traefik gateway | No direct WordPress port |
| `docker-compose.local.yml` | Local plugin development | `127.0.0.1:8081` |
| `docker-compose.ziptest.yml` | Clean release-ZIP installation test | `127.0.0.1:8082` |

WordPress, MariaDB, plugin releases, credentials, certificates, and production data are not stored in this repository.

## Requirements

- Linux with Docker Engine and Docker Compose v2
- WSL2 with Docker Engine is supported; Docker Desktop is not required
- Production: an external Traefik network, such as that provided by [traefik-rescue](https://github.com/ozekihiroshi/traefik-rescue)
- Source-mounted plugin development: `secure-s3-storage-for-wordpress` in the sibling directory

## Local development

```bash
cp .env.example .env
# Replace both change_me values.
docker compose -f docker-compose.local.yml up -d --build
```

Open <http://localhost:8081>. Named volumes are retained by `docker compose ... down`; do not add `--volumes` unless deleting the local site and database is intentional.

## Clean ZIP test

The ZIP-test stack deliberately does not mount plugin source:

```bash
docker compose -f docker-compose.ziptest.yml up -d --build
```

Open <http://localhost:8082>, then install the release ZIP through WordPress or WP-CLI.

See [8082 private media storage (Japanese)](docs/ziptest-media-storage.md) for the dedicated work volume and precautions when updating an existing stack under a legacy Compose project name.

## Production

1. Start the external gateway and create its shared network (default `rescue_proxy`).
2. Copy `.env.example` to `.env`; replace credentials and set `WORDPRESS_HOST`.
3. Validate and start the stack.

```bash
docker compose --env-file .env config --quiet
docker compose up -d
```

Only WordPress joins the proxy network. MariaDB remains private and has no published host port. See [Production with Traefik Rescue](docs/production-with-traefik-rescue.md) and [Backup and restore](docs/backup-and-restore.md).

## Validation

```bash
docker compose --env-file .env.example -f docker-compose.yml config --quiet
docker compose --env-file .env.example -f docker-compose.local.yml config --quiet
docker compose --env-file .env.example -f docker-compose.ziptest.yml config --quiet
```

## Security boundary

- Never commit `.env`, SQL dumps, keys, certificates, or production uploads.
- Pin and test image patch releases or digests for controlled production rollouts.
- Back up both the database and WordPress files; periodically test restoration in isolation.
- Keep WordPress, themes, plugins, images, Docker Engine, and the host OS updated.

See [SECURITY.md](SECURITY.md) for vulnerability reporting and [CHANGELOG.md](CHANGELOG.md) for alpha limitations.

## License

Copyright 2026 Hiroshi Ozeki. GNU General Public License version 3 or later. See [LICENSE](LICENSE).
