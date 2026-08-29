# Production with Traefik Rescue

The external gateway owns host ports 80 and 443; WP Rescue publishes no production host port.

## Network model

- `proxy`: existing external network, default physical name `rescue_proxy`
- `internal`: private network shared by WordPress, MariaDB, and optional WP-CLI
- MariaDB never joins `proxy` and declares no `ports`

The `traefik.rescue.gateway=true` label is required by the recommended constrained Docker provider. A different Traefik deployment must deliberately align its provider constraints, network, entrypoint, and certificate resolver.

## Checklist

1. Create DNS for the WordPress hostname.
2. Start the gateway and verify its external network.
3. Copy `.env.example` to `.env` and replace all sample credentials.
4. Set the hostname, network, and certificate resolver.
5. Select and test controlled image tags or digests.
6. Run `docker compose --env-file .env config --quiet`.
7. Confirm that only WordPress joins the proxy network and MariaDB has no published port.
8. Start the stack, inspect status and logs, and complete WordPress installation over HTTPS.
9. Establish and test database and file backups before accepting production data.

Do not attach MariaDB to the proxy network for convenience. Use `docker compose exec`, an isolated temporary tool container, or an authenticated tunnel.
