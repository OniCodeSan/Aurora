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
        echo '<div class="wrap aurora-system-status-wrap">';
        echo '<div class="aurora-system-status-header">';
        echo '<div>';
        echo '<h1>' . esc_html__( 'Aurora - System Status', 'aurora-enterprise' ) . '</h1>';
        echo '</div>';
        echo '<div class="aurora-system-status-controls">';
        echo '<span class="aurora-system-status-label">' . esc_html__( 'Health:', 'aurora-enterprise' ) . '</span>';
        echo '<span id="aurora-system-status-health-badge" class="aurora-severity-pill is-info">...</span>';
        echo '<span class="aurora-system-status-label">' . esc_html__( 'Last update:', 'aurora-enterprise' ) . '</span>';
        echo '<span id="aurora-system-status-last-update">-</span>';
        echo '<button type="button" class="button" id="aurora-system-status-refresh">' . esc_html__( 'Refresh now', 'aurora-enterprise' ) . '</button>';
        echo '<button type="button" class="button button-secondary" id="aurora-system-status-toggle-auto" data-state="on">' . esc_html__( 'Auto-refresh: ON', 'aurora-enterprise' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<p class="aurora-system-status-next-poll">' . esc_html__( 'Next poll in:', 'aurora-enterprise' ) . ' <span id="aurora-system-status-next-poll-seconds">5s</span></p>';
        echo '<div id="aurora-system-status-notices"></div>';
        echo '<div id="aurora-system-status-overlay" class="aurora-system-status-overlay" hidden>';
        echo '<div class="aurora-system-status-overlay-card">';
        echo '<h2>' . esc_html__( 'Sessione scaduta', 'aurora-enterprise' ) . '</h2>';
        echo '<p>' . esc_html__( 'Devi accedere di nuovo per continuare.', 'aurora-enterprise' ) . '</p>';
        echo '<a class="button button-primary" href="' . esc_url( wp_login_url( admin_url( 'admin.php?page=aurora-system-status' ) ) ) . '">' . esc_html__( 'Apri login', 'aurora-enterprise' ) . '</a>';
        echo '</div>';
        echo '</div>';
        echo '<div id="aurora-system-status-root"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-system-status' ) === false ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        $cssPath = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/css/system-status.css';
        $jsPath  = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/js/system-status.js';
        $cssVer  = file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : $ver;
        $jsVer   = file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : $ver;

        wp_enqueue_style( 'aurora-system-status', plugins_url( 'assets/admin/css/system-status.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $cssVer );
        wp_enqueue_script( 'aurora-system-status', plugins_url( 'assets/admin/js/system-status.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $jsVer, true );
        wp_localize_script( 'aurora-system-status', 'auroraSystemStatusUi', [
            'restBase'      => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'pollMs'        => 5000,
            'maxBackoffMs'  => 60000,
            'routes'        => [
                'status'      => 'ops-ui-status',
                'sweepLeases' => 'trigger/sweep-leases',
            ],
        ] );
    }
}
