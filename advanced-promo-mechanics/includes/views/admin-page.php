<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Promozioni avanzate', 'advanced-promo-mechanics' ); ?></h1>
    <p><?php esc_html_e( 'Gestisci regole promo senza plugin terzi.', 'advanced-promo-mechanics' ); ?></p>

    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=apm_rule' ) ); ?>">
        <?php esc_html_e( 'Crea nuova regola', 'advanced-promo-mechanics' ); ?>
    </a>

    <table class="wp-list-table widefat">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Nome', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Tipo', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Stato', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Priorità', 'advanced-promo-mechanics' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rules as $rule ) : ?>
            <tr>
                <td><a href="<?php echo esc_url( get_edit_post_link( $rule['id'] ) ); ?>"><?php echo esc_html( $rule['name'] ); ?></a></td>
                <td><?php echo esc_html( $rule['type'] ); ?></td>
                <td><?php echo $rule['enabled'] ? esc_html__( 'Attiva', 'advanced-promo-mechanics' ) : esc_html__( 'Disattiva', 'advanced-promo-mechanics' ); ?></td>
                <td><?php echo esc_html( $rule['priority'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" action="options.php" class="apm-settings">
        <?php
            settings_fields( 'apm_settings' );
            $debug  = ! empty( $settings['debug'] );
            $force  = ! empty( $settings['force_gifts'] );
            $dry    = ! empty( $settings['dry_run'] );
        ?>
        <h2><?php esc_html_e( 'Impostazioni globali', 'advanced-promo-mechanics' ); ?></h2>
        <label>
            <input type="checkbox" name="apm_settings[debug]" value="1" <?php checked( $debug ); ?> />
            <?php esc_html_e( 'Abilita logging debug', 'advanced-promo-mechanics' ); ?>
        </label>
        <br />
        <label>
            <input type="checkbox" name="apm_settings[force_gifts]" value="1" <?php checked( $force ); ?> />
            <?php esc_html_e( 'Forza ri-aggiunta omaggi', 'advanced-promo-mechanics' ); ?>
        </label>
        <br />
        <label>
            <input type="checkbox" name="apm_settings[dry_run]" value="1" <?php checked( $dry ); ?> />
            <?php esc_html_e( 'Modalità dry-run (calcola senza modificare il carrello)', 'advanced-promo-mechanics' ); ?>
        </label>
        <?php submit_button(); ?>
    </form>
</div>
