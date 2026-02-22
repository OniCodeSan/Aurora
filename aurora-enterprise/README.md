
## WP-CLI (safe mode)

When running WP-CLI against this dev stack on WordPress < 6.8, WooCommerce can break CLI commands by invoking `wp_create_nonce()` too early. Use the helper script to automatically skip WooCommerce while still loading Aurora plugins:

```bash
./bin/wp-safe.sh plugin list --allow-root
```

The script wraps `docker compose exec worker wp --skip-plugins=woocommerce` from the WordPress project root.

## Upgrade Script
Run `wp aurora upgrade` (or `./bin/aurora-upgrade.sh`) to apply migrations + option defaults + sanity checks. Requires the `wordpress` docker stack running.

### WP-CLI usage
- Use `./bin/wp-safe.sh` **only** for commands that can skip WooCommerce (admin/read-only tasks).
- For worker/rebuild/feed commands (or anything requiring WooCommerce), run `docker compose exec worker wp --allow-root …` directly.

## Snapshot V2 flag
- Default: OFF (`aurora_snapshot_v2_enabled = 0`). Enable only in test/pilot envs.
- Rebuilds in V2 mode write to snapshot tables; disabling the flag falls back to legacy staging indexes without dropping snapshot data.
- Feed enqueue + dashboard expose a guardrail: if versions are not aligned (or pending), feeds are blocked until all three channels share the same cut.
- Rollback: `wp option update aurora_snapshot_v2_enabled 0` + run legacy rebuilds if required (snapshot tables remain untouched).
