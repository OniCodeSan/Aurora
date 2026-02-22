<?php
namespace Aurora\Enterprise\Admin;

class Dashboard {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
            __( 'Aurora Enterprise Indexer', 'aurora-enterprise' ),
            __( 'Aurora Indexer', 'aurora-enterprise' ),
            'manage_woocommerce',
            'aurora-enterprise-indexer',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="wrap aurora-enterprise-admin">';
        echo '<h1>' . esc_html__( 'Aurora Enterprise – Stato indicizzazione', 'aurora-enterprise' ) . '</h1>';
        echo '<div id="aurora-enterprise-dashboard"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        $allowed = [
            'woocommerce_page_aurora-enterprise-indexer',
            'aurora-project_page_aurora-enterprise-indexer',
        ];
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_enqueue_style( 'aurora-enterprise-admin', plugins_url( 'assets/admin/css/dashboard.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], AURORA_ENTERPRISE_VERSION );
        wp_enqueue_script( 'aurora-enterprise-admin', plugins_url( 'assets/admin/js/dashboard.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-element', 'wp-api-fetch' ], AURORA_ENTERPRISE_VERSION, true );
        wp_localize_script( 'aurora-enterprise-admin', 'auroraDashboard', [
            'restBase'      => trailingslashit( rest_url( 'aurora/v1' ) ),
            'dashboardUrl'  => rest_url( 'aurora/v1/dashboard' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
