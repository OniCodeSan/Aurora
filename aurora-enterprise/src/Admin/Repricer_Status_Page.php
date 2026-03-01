<?php
namespace Aurora\Enterprise\Admin;

class Repricer_Status_Page {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        $parent = 'aurora-project';
        add_submenu_page(
            $parent,
            __( 'Aurora Repricer', 'aurora-enterprise' ),
            __( 'Repricer', 'aurora-enterprise' ),
            'manage_woocommerce',
            'aurora-repricer',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="wrap aurora-repricer-status">';
        echo '<h1>' . esc_html__( 'Aurora – Repricer', 'aurora-enterprise' ) . '</h1>';
        echo '<div id="aurora-repricer-root" data-rest="' . esc_attr( rest_url( 'aurora/v1' ) ) . '"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-repricer' ) === false ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        wp_enqueue_style( 'aurora-repricer-status', plugins_url( 'assets/admin/css/repricer-status.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $ver );
        wp_enqueue_script( 'aurora-repricer-status', plugins_url( 'assets/admin/js/repricer-status.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-api-fetch' ], $ver, true );
        wp_localize_script( 'aurora-repricer-status', 'auroraRepricer', [
            'restBase' => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'pollMs'   => 3000,
        ] );
    }
}
