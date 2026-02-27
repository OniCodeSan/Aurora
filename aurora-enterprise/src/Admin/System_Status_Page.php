<?php
namespace Aurora\Enterprise\Admin;

class System_Status_Page {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        $parent = 'aurora-project';
        add_submenu_page(
            $parent,
            __( 'Aurora System Status', 'aurora-enterprise' ),
            __( 'System Status', 'aurora-enterprise' ),
            'manage_woocommerce',
            'aurora-system-status',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="wrap aurora-system-status">';
        echo '<h1>' . esc_html__( 'Aurora – System Status', 'aurora-enterprise' ) . '</h1>';
        echo '<div id="aurora-system-status-root" data-rest="' . esc_attr( rest_url( 'aurora/v1/system-status' ) ) . '"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-system-status' ) === false ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        wp_enqueue_style( 'aurora-system-status', plugins_url( 'assets/admin/css/system-status.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $ver );
        wp_enqueue_script( 'aurora-system-status', plugins_url( 'assets/admin/js/system-status.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-api-fetch' ], $ver, true );
        wp_localize_script( 'aurora-system-status', 'auroraSystemStatus', [
            'restBase' => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'pollMs'   => 5000,
        ] );
    }
}
