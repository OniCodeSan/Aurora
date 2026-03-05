<?php
namespace Aurora\Enterprise\Admin;

class Repricer_Page {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
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
        echo '<div class="wrap aurora-repricer-wrap">';
        echo '<div class="aurora-repricer-header">';
        echo '<div class="aurora-repricer-header-left">';
        echo '<h1>' . esc_html__( 'Aurora - Repricer', 'aurora-enterprise' ) . '</h1>';
        echo '<p class="aurora-repricer-subtitle">' . esc_html__( 'Console operativa pricing asincrono', 'aurora-enterprise' ) . '</p>';
        echo '</div>';
        echo '<div class="aurora-repricer-header-right">';
        echo '<span class="aurora-repricer-healthline-label">' . esc_html__( 'System Health', 'aurora-enterprise' ) . '</span>';
        echo '<span id="aurora-repricer-health-badge" class="aurora-repricer-badge aurora-repricer-badge--pending">...</span>';
        echo '<span class="aurora-repricer-healthline-label">' . esc_html__( 'Ultimo aggiornamento', 'aurora-enterprise' ) . '</span>';
        echo '<span id="aurora-repricer-last-update">-</span>';
        echo '<button type="button" class="button button-secondary" id="aurora-repricer-refresh-now">' . esc_html__( 'Aggiorna', 'aurora-enterprise' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<div id="aurora-repricer-notices"></div>';
        echo '<div id="aurora-repricer-session-overlay" class="aurora-repricer-overlay" hidden>';
        echo '<div class="aurora-repricer-overlay-card">';
        echo '<h2>' . esc_html__( 'Sessione scaduta', 'aurora-enterprise' ) . '</h2>';
        echo '<p>' . esc_html__( 'Ricarica la pagina per ripristinare i permessi di scrittura.', 'aurora-enterprise' ) . '</p>';
        echo '<button type="button" class="button button-primary" id="aurora-repricer-overlay-reload">' . esc_html__( 'Ricarica pagina', 'aurora-enterprise' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<div id="aurora-repricer-root"></div>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ) : void {
        if ( strpos( $hook, 'aurora-repricer' ) === false ) {
            return;
        }
        $ver = defined( 'AURORA_ENTERPRISE_VERSION' ) ? AURORA_ENTERPRISE_VERSION : '0.1.0';
        $cssPath = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/css/aurora-repricer.css';
        $jsPath  = AURORA_ENTERPRISE_PLUGIN_DIR . 'assets/admin/js/aurora-repricer.js';
        $cssVer  = file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : $ver;
        $jsVer   = file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : $ver;

        wp_enqueue_style( 'aurora-repricer', plugins_url( 'assets/admin/css/aurora-repricer.css', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $cssVer );
        wp_enqueue_script( 'aurora-repricer', plugins_url( 'assets/admin/js/aurora-repricer.js', AURORA_ENTERPRISE_PLUGIN_FILE ), [], $jsVer, true );
        wp_localize_script( 'aurora-repricer', 'auroraRepricerUi', [
            'restUrl'   => trailingslashit( rest_url( 'aurora/v1' ) ),
            'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'pollMs'    => 5000,
            'maxPollMs' => 15000,
            'routes'    => [
                'status'    => 'system-status',
                'tick'      => 'repricer/scheduler/tick',
                'run'       => 'repricer/run',
                'apply'     => 'repricer/apply',
                'runAll'    => 'repricer/run-all',
                'preview'   => 'repricer/preview',
                'rollback'  => 'repricer/rollback',
                'rulesList' => 'repricer/rules',
                'ruleGet'   => 'repricer/rules/%id%',
                'ruleCreate'=> 'repricer/rules',
                'ruleUpdate'=> 'repricer/rules/%id%',
                'rulePreview'=> 'repricer/rules/%id%/preview-scope',
                'ruleOptions'=> 'repricer/rules/options',
            ],
        ] );
    }
}
