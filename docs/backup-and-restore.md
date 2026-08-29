# Backup and restore

A usable WordPress backup needs both MariaDB and the WordPress files volume. A database-only dump does not contain uploads, themes, or plugin files.

- Store backups encrypted with retention and access controls.
- Keep credentials out of shell history and command output.
- Record image versions and the repository revision with each backup set.
- Test restoration in an isolated Compose project.

Example database dump pattern:

```bash
umask 077
mkdir -p backups
docker compose exec -T wp-rescue-db sh -c \
  'exec mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' \
  > "backups/wp-rescue-$(date +%Y%m%d-%H%M%S).sql"
```

Treat dumps as sensitive data. Back up `wp_rescue_html` with a trusted volume-backup tool while preserving ownership and permissions. Rehearse restoration using empty, non-production volumes and credentials; never overwrite the only production database during a test.
