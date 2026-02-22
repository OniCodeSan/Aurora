
## WP-CLI (safe mode)

When running WP-CLI against this dev stack on WordPress < 6.8, WooCommerce can break CLI commands by invoking `wp_create_nonce()` too early. Use the helper script to automatically skip WooCommerce while still loading Aurora plugins:

```bash
./bin/wp-safe.sh plugin list --allow-root
```

The script wraps `docker compose exec worker wp --skip-plugins=woocommerce` from the WordPress project root.
