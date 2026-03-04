<?php
namespace Aurora\Enterprise\Admin;

class Dashboard {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        add_menu_page(
            __( 'Aurora', 'aurora-enterprise' ),
            __( 'Aurora', 'aurora-enterprise' ),
            'manage_woocommerce',
            'aurora-project',
            [ $this, 'render_page' ],
            'dashicons-admin-generic',
            56
        );

        add_submenu_page(
            'aurora-project',
            __( 'Aurora Feed Hub', 'aurora-enterprise' ),
            __( 'Feed Hub', 'aurora-enterprise' ),
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
        echo '<h1>' . esc_html__( 'Aurora Enterprise - Feed Hub', 'aurora-enterprise' ) . '</h1>';
        echo '<div id="aurora-enterprise-dashboard"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-enterprise-indexer' ) === false ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        $cssPath = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/css/dashboard.css';
        $jsPath  = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/js/dashboard.js';
        $cssVer  = file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : $ver;
        $jsVer   = file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : $ver;

        wp_enqueue_style( 'aurora-enterprise-admin', plugins_url( 'assets/admin/css/dashboard.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $cssVer );
        wp_enqueue_script( 'aurora-enterprise-admin', plugins_url( 'assets/admin/js/dashboard.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-element', 'wp-api-fetch' ], $jsVer, true );
        wp_localize_script( 'aurora-enterprise-admin', 'auroraDashboard', [
            'restBase'      => trailingslashit( rest_url( 'aurora/v1' ) ),
            'dashboardPath' => '/aurora/v1/ops-ui-status',
            'rebuildPath'   => '/aurora/v1/trigger/rebuild',
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'feedIntegrationsPath' => '/aurora/v1/feed/integrations',
        ] );
    }
}
