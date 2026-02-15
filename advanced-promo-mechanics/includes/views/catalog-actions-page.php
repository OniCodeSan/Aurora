<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Azioni massiche catalogo', 'advanced-promo-mechanics' ); ?></h1>

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $license_active ) : ?>
        <div class="notice notice-warning"><p><?php esc_html_e( 'Inserisci una license key valida per usare le azioni massiche.', 'advanced-promo-mechanics' ); ?></p></div>
    <?php endif; ?>


    <div class="apm-license-card">
        <p><strong><?php esc_html_e( 'Stato licenza:', 'advanced-promo-mechanics' ); ?></strong> <?php echo esc_html( $status_message ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-license-form inline">
            <?php wp_nonce_field( 'apm_save_license' ); ?>
            <input type="hidden" name="action" value="apm_save_license" />
            <label>
                <span><?php esc_html_e( 'License key', 'advanced-promo-mechanics' ); ?></span>
                <input type="text" name="apm_license_key" value="<?php echo esc_attr( $license_key ); ?>" placeholder="APM-XXXX-XXXX" />
            </label>
            <?php submit_button( __( 'Aggiorna licenza', 'advanced-promo-mechanics' ), 'secondary', 'submit_apm_license', false ); ?>
        </form>
    </div>
    <?php $form_classes = $license_active ? 'apm-catalog-form' : 'apm-catalog-form is-locked'; ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo esc_attr( $form_classes ); ?>">
        <?php wp_nonce_field( 'apm_catalog_action' ); ?>
        <input type="hidden" name="action" value="apm_catalog_action" />

        <section class="apm-panel is-active">
            <h2><?php esc_html_e( 'Filtri prodotti', 'advanced-promo-mechanics' ); ?></h2>
            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Stato prodotto', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_filter_status">
                        <option value=""><?php esc_html_e( 'Qualsiasi', 'advanced-promo-mechanics' ); ?></option>
                        <option value="publish"><?php esc_html_e( 'Attivi', 'advanced-promo-mechanics' ); ?></option>
                        <option value="draft"><?php esc_html_e( 'Bozza', 'advanced-promo-mechanics' ); ?></option>
                        <option value="pending"><?php esc_html_e( 'In attesa', 'advanced-promo-mechanics' ); ?></option>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Categorie', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_filter_category[]" multiple>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>
        <section class="apm-panel">
            <h2><?php esc_html_e( 'Export completo', 'advanced-promo-mechanics' ); ?></h2>
            <p><?php esc_html_e( 'Scarica un CSV con tutto il catalogo (ignora i filtri sopra).', 'advanced-promo-mechanics' ); ?></p>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=apm_export_full_catalog' ), 'apm_export_full_catalog' ) ); ?>"><?php esc_html_e( 'Esporta tutto il catalogo', 'advanced-promo-mechanics' ); ?></a>
        </section>

        <section class="apm-panel">
            <h2><?php esc_html_e( 'Azione', 'advanced-promo-mechanics' ); ?></h2>
            <label>
                <span><?php esc_html_e( 'Seleziona azione', 'advanced-promo-mechanics' ); ?></span>
                <select name="apm_action" required>
                    <option value="">—</option>
                    <option value="activate"><?php esc_html_e( 'Attiva prodotti', 'advanced-promo-mechanics' ); ?></option>
                    <option value="deactivate"><?php esc_html_e( 'Disattiva prodotti', 'advanced-promo-mechanics' ); ?></option>
                    <option value="change_category"><?php esc_html_e( 'Cambia categoria', 'advanced-promo-mechanics' ); ?></option>
                    <option value="export_csv"><?php esc_html_e( 'Esporta in CSV', 'advanced-promo-mechanics' ); ?></option>
                </select>
            </label>

            <div class="apm-two-cols">
                <label>
                    <span><?php esc_html_e( 'Nuova categoria (per cambio categoria)', 'advanced-promo-mechanics' ); ?></span>
                    <select name="apm_target_category">
                        <option value=""><?php esc_html_e( 'Seleziona categoria', 'advanced-promo-mechanics' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label>
                <span><?php esc_html_e( 'Campi CSV (per export)', 'advanced-promo-mechanics' ); ?></span>
                <select name="apm_csv_fields[]" multiple>
                    <option value="ID">ID</option>
                    <option value="post_title"><?php esc_html_e( 'Titolo', 'advanced-promo-mechanics' ); ?></option>
                    <option value="price"><?php esc_html_e( 'Prezzo', 'advanced-promo-mechanics' ); ?></option>
                    <option value="sku">SKU</option>
                </select>
            </label>
        </section>

        <?php submit_button( __( 'Esegui azione', 'advanced-promo-mechanics' ), 'primary', 'submit_apm_action' ); ?>
    </form>
</div>
