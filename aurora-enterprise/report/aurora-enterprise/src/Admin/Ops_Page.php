<?php
namespace Aurora\Enterprise\Admin;

class Ops_Page {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
            __( 'Aurora Ops', 'aurora-enterprise' ),
            __( 'Ops', 'aurora-enterprise' ),
            'manage_woocommerce',
            'aurora-ops',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="wrap aurora-ops-admin-page">';
        echo '<h1>' . esc_html__( 'Aurora - Ops', 'aurora-enterprise' ) . '</h1>';
        echo '<div id="aurora-ops-root"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-ops' ) === false ) {
            return;
        }

        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        $cssPath = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/css/aurora-admin.css';
        $jsPath  = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/js/aurora-admin.js';
        $cssVer  = file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : $ver;
        $jsVer   = file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : $ver;

        wp_enqueue_style( 'aurora-ops-admin', plugins_url( 'assets/admin/css/aurora-admin.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $cssVer );
        wp_enqueue_script( 'aurora-ops-admin', plugins_url( 'assets/admin/js/aurora-admin.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $jsVer, true );
        wp_localize_script( 'aurora-ops-admin', 'auroraOpsAdmin', [
            'restBase'      => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'pollMs'        => 5000,
            'maxBackoffMs'  => 60000,
            'routes'        => [
                'status'       => 'ops-ui-status',
                'trigger'      => 'trigger',
                'feedEnqueue'  => 'trigger/feed-enqueue',
                'feedRun'      => 'trigger/feed-run',
                'rebuild'      => 'trigger/rebuild',
                'sweepLeases'  => 'trigger/sweep-leases',
                'repricerTick' => 'repricer/scheduler/tick',
            ],
        ] );
    }
}
