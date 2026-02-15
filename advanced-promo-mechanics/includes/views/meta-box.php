<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$type         = $data['type'] ?? 'free_gift';
$enabled      = ! empty( $data['enabled'] );
$priority     = $data['priority'] ?? 10;
$conditions   = $data['conditions'] ?? [];
$config       = $data['config'] ?? [];
$schedule_end = $data['schedule_end'] ?? '';
$schedule_start = $data['schedule_start'] ?? '';

$roles = wp_roles()->roles;
$product_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
if ( is_wp_error( $product_cats ) ) {
    $product_cats = [];
}
$product_tags = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false ] );
if ( is_wp_error( $product_tags ) ) {
    $product_tags = [];
}

wp_nonce_field( 'apm_rule_nonce', 'apm_rule_nonce' );

$tabs = [
    'general'    => __( 'Generale', 'advanced-promo-mechanics' ),
    'conditions' => __( 'Condizioni', 'advanced-promo-mechanics' ),
    'actions'    => __( 'Azioni', 'advanced-promo-mechanics' ),
    'messages'   => __( 'Messaggistica', 'advanced-promo-mechanics' ),
];
?>
<div class="apm-meta" data-apm-meta>
    <div class="apm-tabs" role="tablist">
        <?php $index = 0; foreach ( $tabs as $slug => $label ) : ?>
            <button type="button" class="apm-tabs__btn <?php echo 0 === $index ? 'is-active' : ''; ?>" data-apm-tab-target="<?php echo esc_attr( $slug ); ?>" role="tab" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>">
                <?php echo esc_html( $label ); ?>
            </button>
        <?php $index++; endforeach; ?>
    </div>

    <div class="apm-panels">
        <section class="apm-panel is-active" data-apm-tab="general">
            <div class="apm-field">
                <label>
                    <input type="checkbox" name="apm_rule[enabled]" value="1" <?php checked( $enabled ); ?> />
                    <?php esc_html_e( 'Regola attiva', 'advanced-promo-mechanics' ); ?>
                </label>
            </div>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Tipo regola', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_rule[type]" data-apm-type-select>
                        <option value="free_gift" <?php selected( $type, 'free_gift' ); ?>><?php esc_html_e( 'Omaggio automatico', 'advanced-promo-mechanics' ); ?></option>
                        <option value="bogo" <?php selected( $type, 'bogo' ); ?>><?php esc_html_e( 'Compra 2, il meno caro è gratis', 'advanced-promo-mechanics' ); ?></option>
                        <option value="three_two" <?php selected( $type, 'three_two' ); ?>><?php esc_html_e( '3x2 classico', 'advanced-promo-mechanics' ); ?></option>
                        <option value="quantity" <?php selected( $type, 'quantity' ); ?>><?php esc_html_e( 'Sconto quantità (tiered)', 'advanced-promo-mechanics' ); ?></option>
                        <option value="buyx_gety" <?php selected( $type, 'buyx_gety' ); ?>><?php esc_html_e( 'Compra X, regala Y', 'advanced-promo-mechanics' ); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Priorità (più basso = prima)', 'advanced-promo-mechanics' ); ?></span>
                    <input type="number" min="0" name="apm_rule[priority]" value="<?php echo esc_attr( $priority ); ?>" />
                </label>
            </div>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Inizio validità', 'advanced-promo-mechanics' ); ?></span>
                    <input type="datetime-local" name="apm_rule[schedule_start]" value="<?php echo esc_attr( $schedule_start ); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e( 'Fine validità', 'advanced-promo-mechanics' ); ?></span>
                    <input type="datetime-local" name="apm_rule[schedule_end]" value="<?php echo esc_attr( $schedule_end ); ?>" />
                </label>
            </div>

            <div class="apm-checkbox-grid">
                <label>
                    <input type="checkbox" name="apm_rule[config][exclusive]" value="1" <?php checked( ! empty( $config['exclusive'] ) ); ?> />
                    <?php esc_html_e( 'Esclusiva (blocca altre promo)', 'advanced-promo-mechanics' ); ?>
                </label>
                <label>
                    <input type="checkbox" name="apm_rule[config][repeatable]" value="1" <?php checked( ! empty( $config['repeatable'] ) ); ?> />
                    <?php esc_html_e( 'Ripetibile finché le condizioni sono valide', 'advanced-promo-mechanics' ); ?>
                </label>
                <label>
                    <input type="checkbox" name="apm_rule[config][exclude_discounted]" value="1" <?php checked( ! empty( $config['exclude_discounted'] ) ); ?> />
                    <?php esc_html_e( 'Escludi prodotti già scontati', 'advanced-promo-mechanics' ); ?>
                </label>
            </div>

            <label>
                <span><?php esc_html_e( 'Limite per ordine (0 = illimitato)', 'advanced-promo-mechanics' ); ?></span>
                <input type="number" min="0" name="apm_rule[config][limit_per_order]" value="<?php echo esc_attr( $config['limit_per_order'] ?? 0 ); ?>" />
            </label>
        </section>

        <section class="apm-panel" data-apm-tab="conditions">
            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Subtotale minimo (€)', 'advanced-promo-mechanics' ); ?></span>
                    <input type="number" step="0.01" min="0" name="apm_rule[conditions][min_subtotal]" value="<?php echo esc_attr( $conditions['min_subtotal'] ?? '' ); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e( 'Quantità minima pezzi', 'advanced-promo-mechanics' ); ?></span>
                    <input type="number" min="0" name="apm_rule[conditions][min_qty]" value="<?php echo esc_attr( $conditions['min_qty'] ?? '' ); ?>" />
                </label>
            </div>

            <label>
                <span><?php esc_html_e( 'Coupon richiesto', 'advanced-promo-mechanics' ); ?></span>
                <input type="text" name="apm_rule[conditions][coupon]" value="<?php echo esc_attr( $conditions['coupon'] ?? '' ); ?>" />
            </label>

            <label>
                <span><?php esc_html_e( 'Ruoli cliente', 'advanced-promo-mechanics' ); ?></span>
                <select name="apm_rule[conditions][customer_roles][]" multiple>
                    <?php foreach ( $roles as $role_key => $role ) : ?>
                        <option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( in_array( $role_key, $conditions['customer_roles'] ?? [], true ) ); ?>>
                            <?php echo esc_html( $role['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'SKU inclusi (comma separated)', 'advanced-promo-mechanics' ); ?></span>
                    <input type="text" name="apm_rule[conditions][include_skus]" value="<?php echo esc_attr( implode( ',', $conditions['include_skus'] ?? [] ) ); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e( 'SKU esclusi', 'advanced-promo-mechanics' ); ?></span>
                    <input type="text" name="apm_rule[conditions][exclude_skus]" value="<?php echo esc_attr( implode( ',', $conditions['exclude_skus'] ?? [] ) ); ?>" />
                </label>
            </div>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Categorie incluse', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_rule[conditions][include_terms][]" multiple>
                        <?php foreach ( $product_cats as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, $conditions['include_terms'] ?? [], true ) ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Categorie escluse', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_rule[conditions][exclude_terms][]" multiple>
                        <?php foreach ( $product_cats as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, $conditions['exclude_terms'] ?? [], true ) ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Tag inclusi', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_rule[conditions][include_tags][]" multiple>
                        <?php foreach ( $product_tags as $tag ) : ?>
                            <option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( in_array( $tag->term_id, $conditions['include_tags'] ?? [], true ) ); ?>>
                                <?php echo esc_html( $tag->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Tag esclusi', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_rule[conditions][exclude_tags][]" multiple>
                        <?php foreach ( $product_tags as $tag ) : ?>
                            <option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( in_array( $tag->term_id, $conditions['exclude_tags'] ?? [], true ) ); ?>>
                                <?php echo esc_html( $tag->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>

        <section class="apm-panel" data-apm-tab="actions">
            <div class="apm-action-group" data-type-scope="free_gift,buyx_gety">
                <label>
                    <span><?php esc_html_e( 'Prodotti omaggio (ID separati da virgola)', 'advanced-promo-mechanics' ); ?></span>
                    <input type="text" name="apm_rule[config][gift_products]" value="<?php echo esc_attr( implode( ',', $config['gift_products'] ?? [] ) ); ?>" />
                </label>
                <div class="apm-checkbox-grid">
                    <label>
                        <input type="checkbox" name="apm_rule[config][auto_add]" value="1" <?php checked( ! empty( $config['auto_add'] ) ); ?> />
                        <?php esc_html_e( 'Aggiunta automatica al carrello', 'advanced-promo-mechanics' ); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="apm_rule[config][choice_mode]" value="1" <?php checked( ! empty( $config['choice_mode'] ) ); ?> />
                        <?php esc_html_e( 'Permetti scelta manuale dell’omaggio', 'advanced-promo-mechanics' ); ?>
                    </label>
                </div>
            </div>

            <div class="apm-action-group" data-type-scope="buyx_gety">
                <div class="apm-two-cols">
                    <label>
                        <span><?php esc_html_e( 'Compra X (quantità richiesta)', 'advanced-promo-mechanics' ); ?></span>
                        <input type="number" min="1" name="apm_rule[config][buy_qty]" value="<?php echo esc_attr( $config['buy_qty'] ?? 1 ); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Regala Y (pezzi per bundle)', 'advanced-promo-mechanics' ); ?></span>
                        <input type="number" min="1" name="apm_rule[config][gift_qty]" value="<?php echo esc_attr( $config['gift_qty'] ?? 1 ); ?>" />
                    </label>
                </div>
            </div>

            <div class="apm-action-group" data-type-scope="bogo,three_two">
                <label>
                    <input type="checkbox" name="apm_rule[config][repeatable]" value="1" <?php checked( ! empty( $config['repeatable'] ) ); ?> />
                    <?php esc_html_e( 'Applica per ogni coppia/terna valida', 'advanced-promo-mechanics' ); ?>
                </label>
            </div>

            <div class="apm-action-group" data-type-scope="quantity">
                <label>
                    <span><?php esc_html_e( 'Scaglioni (JSON es. [{"from":2,"to":3,"discount_type":"percent","value":5}])', 'advanced-promo-mechanics' ); ?></span>
                    <textarea name="apm_rule[config][tiers_raw]" rows="4"><?php echo esc_textarea( wp_json_encode( $config['tiers'] ?? [] ) ); ?></textarea>
                </label>
            </div>
        </section>

        <section class="apm-panel" data-apm-tab="messages">
            <label>
                <span><?php esc_html_e( 'Messaggio in carrello', 'advanced-promo-mechanics' ); ?></span>
                <textarea name="apm_rule[config][message_cart]" rows="3"><?php echo esc_textarea( $config['message_cart'] ?? '' ); ?></textarea>
            </label>
            <label>
                <span><?php esc_html_e( 'Messaggio in checkout', 'advanced-promo-mechanics' ); ?></span>
                <textarea name="apm_rule[config][message_checkout]" rows="3"><?php echo esc_textarea( $config['message_checkout'] ?? '' ); ?></textarea>
            </label>
        </section>
    </div>
</div>
