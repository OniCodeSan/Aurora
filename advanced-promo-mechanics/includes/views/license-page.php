<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Licenza Advanced Promo Mechanics', 'advanced-promo-mechanics' ); ?></h1>

    <?php if ( isset( $_GET['apm_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
        <div class="notice notice-success"><p><?php echo esc_html( wp_unslash( $_GET['apm_notice'] ) ); ?></p></div>
    <?php endif; ?>

    <div class="apm-license-card">
        <p><strong><?php esc_html_e( 'Stato:', 'advanced-promo-mechanics' ); ?></strong> <?php echo esc_html( $status_message ); ?></p>
        <?php if ( empty( $license_key ) ) : ?>
            <p><?php printf( esc_html__( 'Ti restano %d giorni di prova gratuita.', 'advanced-promo-mechanics' ), $days_left ); ?></p>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-license-form">
        <?php wp_nonce_field( 'apm_save_license' ); ?>
        <input type="hidden" name="action" value="apm_save_license" />
        <label>
            <span><?php esc_html_e( 'License key', 'advanced-promo-mechanics' ); ?></span>
            <input type="text" name="apm_license_key" value="<?php echo esc_attr( $license_key ); ?>" placeholder="APM-XXXX-XXXX" />
        </label>
        <p class="description">
            <?php esc_html_e( 'Inserisci la chiave ricevuta dopo l’acquisto. Lascia vuoto e salva per rimuovere la licenza.', 'advanced-promo-mechanics' ); ?>
        </p>
        <?php submit_button( __( 'Salva licenza', 'advanced-promo-mechanics' ) ); ?>
    </form>
</div>
