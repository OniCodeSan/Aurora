<?php
namespace Aurora\Enterprise\Admin;

class System_Status_Page {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
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
        $allowed = [
            'aurora-project_page_aurora-system-status',
        ];
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_enqueue_style( 'aurora-system-status', plugins_url( 'assets/admin/css/system-status.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], AURORA_ENTERPRISE_VERSION );
        wp_enqueue_script( 'aurora-system-status', plugins_url( 'assets/admin/js/system-status.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-api-fetch' ], AURORA_ENTERPRISE_VERSION, true );
        wp_localize_script( 'aurora-system-status', 'auroraSystemStatus', [
            'restPath'         => '/aurora/v1/system-status',
            'triggerSweepPath' => '/aurora/v1/trigger/sweep-leases',
            'nonce'            => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
