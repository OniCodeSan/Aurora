<?php
namespace APM;

class Feed_Module {
    private Feed_Profiles $profiles;
    private Feed_Logs $logs;

    public function __construct( Feed_Profiles $profiles, Feed_Logs $logs ) {
        $this->profiles = $profiles;
        $this->logs     = $logs;
    }

    public function init() : void {
        add_action( 'admin_post_apm_save_feed_profile', [ $this, 'handle_save_profile' ] );
        add_action( 'admin_post_apm_delete_feed_profile', [ $this, 'handle_delete_profile' ] );
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
            __( 'Feed Export', 'advanced-promo-mechanics' ),
            __( 'Feed Export', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-feed-manager',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $profiles   = $this->profiles->all();
        $logs       = $this->logs->latest();
        $paths      = apm_get_feed_paths();
        $manual     = $this->get_manual_steps();
        include APM_PLUGIN_DIR . 'includes/views/feed-manager-page.php';
    }

    public function handle_save_profile() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_feed_profile' );
        $saved = $this->profiles->save( $_POST );
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-feed-manager', 'apm_notice' => rawurlencode( $saved ? __( 'Profilo salvato.', 'advanced-promo-mechanics' ) : __( 'Errore salvataggio profilo.', 'advanced-promo-mechanics' ) ) ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete_profile() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_delete_feed_profile' );
        $id = absint( $_POST['feed_profile_id'] ?? 0 );
        if ( $id ) {
            $this->profiles->delete( $id );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-feed-manager' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function get_manual_steps() : array {
        $paths = apm_get_feed_paths();
        return [
            __( '1. Prepara il profilo export scegliendo merchant (Trovaprezzi, Google Shopping, Custom) e formato XML/CSV.', 'advanced-promo-mechanics' ),
            sprintf( __( '2. Il feed verrà salvato nella cartella pubblica: %s (URL: %s). Usa questo percorso in strumenti terzi.', 'advanced-promo-mechanics' ), '<code>' . esc_html( $paths['dir'] ) . '</code>', '<code>' . esc_html( $paths['url'] ) . '</code>' ),
            __( '3. Deposita qui eventuali XML personalizzati: li leggeremo per distribuirli ai merchant.', 'advanced-promo-mechanics' ),
            __( '4. Configura l’export destination: URL del merchant o endpoint FTP/SFTP esterno.', 'advanced-promo-mechanics' ),
            __( '5. Consulta il log esecuzioni per verificare esito e file generati.', 'advanced-promo-mechanics' ),
        ];
    }
}
