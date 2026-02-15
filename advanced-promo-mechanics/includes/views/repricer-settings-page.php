<?php
/** @var array $settings */
/** @var array $accounts */
/** @var array $sku_links */

if ( ! function_exists( 'apm_mask_value' ) ) {
    function apm_mask_value( $value, int $visible = 4 ) : string {
        $value = (string) $value;
        if ( strlen( $value ) <= $visible ) {
            return $value;
        }
        return str_repeat( '•', max( 0, strlen( $value ) - $visible ) ) . substr( $value, - $visible );
    }
}

$amazon_accounts = array_filter( $accounts, static fn( $acc ) => 'amazon' === $acc['marketplace'] );
$ebay_accounts   = array_filter( $accounts, static fn( $acc ) => 'ebay' === $acc['marketplace'] );
$recent_products = get_posts( [
    'post_type'      => 'product',
    'posts_per_page' => 20,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post_status'    => [ 'publish', 'draft' ],
] );
?>
<style>
    .apm-admin .apm-header-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 12px; margin-top: 16px; }
    .apm-admin .apm-stat-card { padding: 14px 18px; border: 1px solid #dcdcde; border-radius: 10px; background: #fff; }
    .apm-admin .apm-stat-card h3 { margin: 0; font-size: 13px; letter-spacing: .02em; color: #666; text-transform: uppercase; }
    .apm-admin .apm-stat-card strong { font-size: 24px; display: block; margin-top: 4px; color: #1d2327; }
    .apm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px,1fr)); gap: 24px; margin-top: 24px; }
    .apm-card { background: #fff; border: 1px solid #dcdcde; border-radius: 12px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,.04); }
    .apm-card h2 { margin-top: 0; display: flex; align-items: center; gap: 8px; font-size: 18px; }
    .apm-account-list { margin-top: 16px; display: grid; gap: 12px; }
    .apm-account-card { border: 1px solid #dcdcde; border-radius: 10px; padding: 14px 16px; background: #f8f9ff; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
    .apm-account-card__meta span { display: block; font-size: 12px; color: #555; }
    .apm-badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .apm-badge__dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .apm-badge--amazon { background: #fef3c7; color: #92400e; }
    .apm-badge--ebay { background: #dbeafe; color: #1d4ed8; }
    .apm-marketplace-forms details { margin-top: 18px; border: 1px solid #dcdcde; border-radius: 10px; padding: 14px 18px; background: #fdfdfd; }
    .apm-marketplace-forms summary { cursor: pointer; font-weight: 600; }
    .apm-marketplace-form { margin-top: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    .apm-marketplace-form label { display: flex; flex-direction: column; font-size: 13px; font-weight: 600; color: #1d2327; }
    .apm-marketplace-form input, .apm-marketplace-form select { margin-top: 4px; }
    .apm-marketplace-form button { grid-column: 1 / -1; }
    .apm-sku-card { margin-top: 24px; }
    .apm-sku-table thead th { font-weight: 600; }
    .apm-sku-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 16px; }
    .apm-sku-form label { font-weight: 600; display: flex; flex-direction: column; }
    .apm-sku-form button { grid-column: 1 / -1; }
    @media (max-width: 782px) { .apm-marketplace-form, .apm-sku-form { grid-template-columns: 1fr; } }
</style>

<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Aurora Repricer', 'advanced-promo-mechanics' ); ?></h1>

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
    <?php endif; ?>

    <div class="apm-header-grid">
        <div class="apm-stat-card">
            <h3><?php esc_html_e( 'Amazon collegati', 'advanced-promo-mechanics' ); ?></h3>
            <strong><?php echo esc_html( count( $amazon_accounts ) ); ?></strong>
        </div>
        <div class="apm-stat-card">
            <h3><?php esc_html_e( 'eBay collegati', 'advanced-promo-mechanics' ); ?></h3>
            <strong><?php echo esc_html( count( $ebay_accounts ) ); ?></strong>
        </div>
        <div class="apm-stat-card">
            <h3><?php esc_html_e( 'Batch repricer', 'advanced-promo-mechanics' ); ?></h3>
            <strong><?php echo esc_html( $settings['batch_size'] ); ?></strong>
        </div>
    </div>

    <div class="apm-grid">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-card">
            <h2>⚙️ <?php esc_html_e( 'Parametri algoritmo', 'advanced-promo-mechanics' ); ?></h2>
            <?php wp_nonce_field( 'apm_save_repricer_settings' ); ?>
            <input type="hidden" name="action" value="apm_save_repricer_settings" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="apm_default_min_margin"><?php esc_html_e( 'Margine minimo di default (%)', 'advanced-promo-mechanics' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="apm_default_min_margin" name="default_min_margin" value="<?php echo esc_attr( $settings['default_min_margin'] ); ?>" step="0.1" min="0" max="100" />
                            <p class="description"><?php esc_html_e( 'Usato se il prodotto non definisce un margine proprio.', 'advanced-promo-mechanics' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="apm_stock_push_threshold"><?php esc_html_e( 'Soglia stock “stock push”', 'advanced-promo-mechanics' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="apm_stock_push_threshold" name="stock_push_threshold" value="<?php echo esc_attr( $settings['stock_push_threshold'] ); ?>" min="0" step="1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="apm_stock_push_discount"><?php esc_html_e( 'Sconto massimo stock push (%)', 'advanced-promo-mechanics' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="apm_stock_push_discount" name="stock_push_discount" value="<?php echo esc_attr( $settings['stock_push_discount'] ); ?>" step="0.1" min="0" max="90" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="apm_stock_push_min_margin"><?php esc_html_e( 'Margine minimo stock push (%)', 'advanced-promo-mechanics' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="apm_stock_push_min_margin" name="stock_push_min_margin" value="<?php echo esc_attr( $settings['stock_push_min_margin'] ); ?>" step="0.1" min="0" max="100" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="apm_batch_size"><?php esc_html_e( 'Batch size per run', 'advanced-promo-mechanics' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="apm_batch_size" name="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" step="1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Frequenza scheduler', 'advanced-promo-mechanics' ); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="schedule" value="5min" <?php checked( $settings['schedule'], '5min' ); ?> /> <?php esc_html_e( 'Ogni 5 minuti (real time)', 'advanced-promo-mechanics' ); ?></label><br />
                                <label><input type="radio" name="schedule" value="15min" <?php checked( $settings['schedule'], '15min' ); ?> /> <?php esc_html_e( 'Ogni 15 minuti (alto carico)', 'advanced-promo-mechanics' ); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'advanced-promo-mechanics' ); ?></button>
            </p>
        </form>

        <div class="apm-card">
            <h2>🌐 <?php esc_html_e( 'Account marketplace', 'advanced-promo-mechanics' ); ?></h2>
            <p><?php esc_html_e( 'Credenziali cifrate lato server. Collega Amazon & eBay per sincronizzare prezzi, stock e feed.', 'advanced-promo-mechanics' ); ?></p>

            <div class="apm-account-list">
                <?php if ( empty( $accounts ) ) : ?>
                    <div class="notice notice-info"><p><?php esc_html_e( 'Nessun account collegato. Aggiungine uno qui sotto.', 'advanced-promo-mechanics' ); ?></p></div>
                <?php else : ?>
                    <?php foreach ( $accounts as $account ) :
                        $data = $account['data'];
                        $badge_class = 'amazon' === $account['marketplace'] ? 'apm-badge apm-badge--amazon' : 'apm-badge apm-badge--ebay';
                        $identifier = 'amazon' === $account['marketplace'] ? ( $data['seller_id'] ?? '' ) : ( $data['ru_name'] ?? '' );
                        ?>
                        <div class="apm-account-card">
                            <div class="apm-account-card__meta">
                                <span class="<?php echo esc_attr( $badge_class ); ?>"><span class="apm-badge__dot"></span><?php echo esc_html( ucfirst( $account['marketplace'] ) ); ?></span>
                                <strong><?php echo esc_html( $account['label'] ); ?></strong>
                                <?php if ( $identifier ) : ?>
                                    <span><?php esc_html_e( 'ID account', 'advanced-promo-mechanics' ); ?>: <?php echo esc_html( apm_mask_value( $identifier ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $account['updated_at'] ) ) : ?>
                                    <span><?php esc_html_e( 'Aggiornato', 'advanced-promo-mechanics' ); ?>: <?php echo esc_html( $account['updated_at'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'apm_delete_marketplace_account' ); ?>
                                <input type="hidden" name="action" value="apm_delete_marketplace_account" />
                                <input type="hidden" name="account_id" value="<?php echo esc_attr( $account['id'] ); ?>" />
                                <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Rimuovere questo account?', 'advanced-promo-mechanics' ) ); ?>');"><?php esc_html_e( 'Rimuovi', 'advanced-promo-mechanics' ); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="apm-marketplace-forms">
                <details open>
                    <summary><?php esc_html_e( 'Connetti Amazon SP-API', 'advanced-promo-mechanics' ); ?></summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-marketplace-form">
                        <?php wp_nonce_field( 'apm_save_marketplace_account' ); ?>
                        <input type="hidden" name="action" value="apm_save_marketplace_account" />
                        <input type="hidden" name="marketplace" value="amazon" />

                        <label>
                            <?php esc_html_e( 'Label account', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="account_label" required placeholder="Amazon EU" />
                        </label>
                        <label>
                            <?php esc_html_e( 'Seller ID', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="amazon_seller_id" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Client ID', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="amazon_client_id" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Client Secret', 'advanced-promo-mechanics' ); ?>
                            <input type="password" name="amazon_client_secret" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Refresh Token', 'advanced-promo-mechanics' ); ?>
                            <input type="password" name="amazon_refresh_token" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Role ARN (IAM)', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="amazon_role_arn" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Marketplace region', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="amazon_marketplace" placeholder="EU / NA / FE" required />
                        </label>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Salva Amazon', 'advanced-promo-mechanics' ); ?></button>
                    </form>
                </details>

                <details>
                    <summary><?php esc_html_e( 'Connetti eBay Sell Feed', 'advanced-promo-mechanics' ); ?></summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-marketplace-form">
                        <?php wp_nonce_field( 'apm_save_marketplace_account' ); ?>
                        <input type="hidden" name="action" value="apm_save_marketplace_account" />
                        <input type="hidden" name="marketplace" value="ebay" />

                        <label>
                            <?php esc_html_e( 'Label account', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="account_label" required placeholder="eBay Italy" />
                        </label>
                        <label>
                            <?php esc_html_e( 'RuName', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="ebay_ru_name" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Client ID', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="ebay_client_id" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Client Secret', 'advanced-promo-mechanics' ); ?>
                            <input type="password" name="ebay_client_secret" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Refresh Token', 'advanced-promo-mechanics' ); ?>
                            <input type="password" name="ebay_refresh_token" required />
                        </label>
                        <label>
                            <?php esc_html_e( 'Marketplace ID', 'advanced-promo-mechanics' ); ?>
                            <input type="text" name="ebay_marketplace_id" required placeholder="EBAY_IT" />
                        </label>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Salva eBay', 'advanced-promo-mechanics' ); ?></button>
                    </form>
                </details>
            </div>
        </div>
    </div>

    <div class="apm-card apm-sku-card">
        <h2>🔗 <?php esc_html_e( 'Mapping SKU ↔ Marketplace', 'advanced-promo-mechanics' ); ?></h2>
        <p><?php esc_html_e( 'Collega i prodotti WooCommerce agli SKU ufficiali di Amazon/eBay. Il repricer userà questo mapping per import export feed.', 'advanced-promo-mechanics' ); ?></p>

        <table class="wp-list-table widefat fixed striped apm-sku-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Prodotto', 'advanced-promo-mechanics' ); ?></th>
                    <th><?php esc_html_e( 'Marketplace', 'advanced-promo-mechanics' ); ?></th>
                    <th><?php esc_html_e( 'SKU marketplace', 'advanced-promo-mechanics' ); ?></th>
                    <th><?php esc_html_e( 'Listing ID', 'advanced-promo-mechanics' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $sku_links ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Nessun mapping registrato.', 'advanced-promo-mechanics' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $sku_links as $link ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( get_the_title( $link['variation_id'] ?: $link['product_id'] ) ); ?>
                                <?php if ( $link['variation_id'] ) : ?>
                                    <span class="description">(<?php printf( 'Variation #%d', $link['variation_id'] ); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( ucfirst( $link['marketplace'] ) ); ?></td>
                            <td><?php echo esc_html( $link['marketplace_sku'] ); ?></td>
                            <td><?php echo esc_html( $link['listing_id'] ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'apm_delete_sku_map' ); ?>
                                    <input type="hidden" name="action" value="apm_delete_sku_map" />
                                    <input type="hidden" name="sku_map_id" value="<?php echo esc_attr( $link['id'] ); ?>" />
                                    <button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Rimuovere questo mapping?', 'advanced-promo-mechanics' ) ); ?>');"><?php esc_html_e( 'Rimuovi', 'advanced-promo-mechanics' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-sku-form">
            <?php wp_nonce_field( 'apm_save_sku_map' ); ?>
            <input type="hidden" name="action" value="apm_save_sku_map" />

            <label>
                <?php esc_html_e( 'Prodotto', 'advanced-promo-mechanics' ); ?>
                <select name="sku_product_id" required>
                    <option value=""><?php esc_html_e( 'Seleziona prodotto…', 'advanced-promo-mechanics' ); ?></option>
                    <?php foreach ( $recent_products as $product ) : ?>
                        <option value="<?php echo esc_attr( $product->ID ); ?>"><?php echo esc_html( $product->post_title . ' (#' . $product->ID . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php esc_html_e( 'Variation ID (opzionale)', 'advanced-promo-mechanics' ); ?>
                <input type="number" name="sku_variation_id" min="0" placeholder="0" />
            </label>

            <label>
                <?php esc_html_e( 'Marketplace', 'advanced-promo-mechanics' ); ?>
                <select name="sku_marketplace" required>
                    <option value="amazon">Amazon</option>
                    <option value="ebay">eBay</option>
                </select>
            </label>

            <label>
                <?php esc_html_e( 'SKU marketplace', 'advanced-promo-mechanics' ); ?>
                <input type="text" name="sku_marketplace_sku" required />
            </label>

            <label>
                <?php esc_html_e( 'Listing ID (opzionale)', 'advanced-promo-mechanics' ); ?>
                <input type="text" name="sku_listing_id" />
            </label>

            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Salva mapping', 'advanced-promo-mechanics' ); ?></button>
        </form>
    </div>
</div>
