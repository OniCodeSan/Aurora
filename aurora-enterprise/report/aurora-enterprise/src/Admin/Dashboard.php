<?php
namespace Aurora\Enterprise\Admin;

class Dashboard {
    private const DASHBOARD_SLUG = 'aurora-dashboard';

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
            __( 'Aurora Dashboard', 'aurora-enterprise' ),
            __( 'Dashboard', 'aurora-enterprise' ),
            'manage_options',
            self::DASHBOARD_SLUG,
            [ $this, 'render_page' ]
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
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap aurora-dashboard-admin">';
        echo '<h1>' . esc_html__( 'Aurora Dashboard', 'aurora-enterprise' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Panoramica operativa Aurora: stato, run recenti ed eventi.', 'aurora-enterprise' ) . '</p>';
        echo '<div id="aurora-dashboard-notices"></div>';
        echo '<div id="aurora-dashboard-root">';
        echo '<p class="aurora-dashboard-loading">' . esc_html__( 'Caricamento dashboard...', 'aurora-enterprise' ) . '</p>';
        echo '</div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( ! $this->is_dashboard_hook( $hook ) ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        $cssPath = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/css/dashboard.css';
        $jsPath  = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/js/dashboard.js';
        $cssVer  = file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : $ver;
        $jsVer   = file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : $ver;

        wp_enqueue_style( 'aurora-enterprise-admin', plugins_url( 'assets/admin/css/dashboard.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $cssVer );
        wp_enqueue_script( 'aurora-enterprise-admin', plugins_url( 'assets/admin/js/dashboard.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [ 'wp-element', 'wp-api-fetch' ], $jsVer, true );
        wp_localize_script( 'aurora-enterprise-admin', 'auroraAdmin', [
            'restBase' => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'urls'     => [
                'systemStatus' => admin_url( 'admin.php?page=aurora-system-status' ),
                'repricer'     => admin_url( 'admin.php?page=aurora-repricer' ),
                'feed'         => admin_url( 'admin.php?page=aurora-enterprise-indexer' ),
            ],
            'routes'   => [
                'summary' => 'dashboard/summary',
                'runs'    => 'dashboard/runs',
                'events'  => 'dashboard/events',
                'action'  => 'dashboard/action',
            ],
        ] );
    }

    private function is_dashboard_hook( string $hook ) : bool {
        $allowed = [
            'toplevel_page_aurora-project',
            'aurora_page_' . self::DASHBOARD_SLUG,
            'aurora_page_aurora-enterprise-indexer',
        ];
        return in_array( $hook, $allowed, true );
    }
}
