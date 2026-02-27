
## WP-CLI (safe mode)

When running WP-CLI against this dev stack on WordPress < 6.8, WooCommerce can break CLI commands by invoking `wp_create_nonce()` too early. Use the helper script to automatically skip WooCommerce while still loading Aurora plugins:

```bash
./bin/wp-safe.sh plugin list --allow-root
```

The script wraps `docker compose exec worker wp --skip-plugins=woocommerce` from the WordPress project root.

## Upgrade Script
Run `wp aurora upgrade` (or `./bin/aurora-upgrade.sh`) to apply migrations + option defaults + shard/snapshot sanity checks. Requires the `wordpress` docker stack running.

### WP-CLI usage
- Use `./bin/wp-safe.sh` **only** for commands that can skip WooCommerce (admin/read-only tasks).
- For worker/rebuild/feed commands (or anything requiring WooCommerce), run `docker compose exec worker wp --allow-root …` directly.

## Snapshot V2 flag
- Default: OFF (`aurora_snapshot_v2_enabled = 0`). Enable only in test/pilot envs.
- Rebuilds in V2 mode write to snapshot tables; disabling the flag falls back to legacy staging indexes without dropping snapshot data.
- Feed enqueue + dashboard expose a guardrail: if versions are not aligned (or pending), feeds are blocked until all three channels share the same cut.
- Rollback: `wp option update aurora_snapshot_v2_enabled 0` + run legacy rebuilds if required (snapshot tables remain untouched).

## Post-seed smoke checks
After running `wp aurora seed products ...`, run these quick checks from the WordPress project root:

```bash
docker compose exec worker wp --allow-root aurora queue status
docker compose exec worker wp --allow-root db query "SELECT COUNT(*) AS seeded FROM wp_postmeta WHERE meta_key = '_aurora_seed_batch' AND meta_value = 'YOUR_LABEL';"
docker compose exec worker wp --allow-root db query "SELECT COUNT(*) AS products FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish';"
docker compose exec worker wp --allow-root db query "SELECT COUNT(*) AS lookup_rows FROM wp_wc_product_meta_lookup;"
```

Or use the bundled smoke script:

```bash
docker compose exec worker bash -lc "cd /var/www/html/wp-content/plugins/aurora-enterprise && LABEL=YOUR_LABEL ./bin/verify-seed-smoke.sh"
```

Expected outcome:
- queue depth coherent with your enqueue strategy (or zero if not enqueued yet)
- `seeded` > 0 for the label used in the command
- product count and lookup count increase consistently after seed/rebuild
- `product_type=simple` term_taxonomy count aligned with real term relationships

Seeder notes:
- `wp aurora seed products` is transactional and expects InnoDB-backed tables.
- `wordpress/seed-products-direct.php` is a workspace helper outside this plugin module and is not part of this repo scope.
