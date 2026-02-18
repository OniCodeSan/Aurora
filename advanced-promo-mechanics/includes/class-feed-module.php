<?php
namespace APM;

class Feed_Module {
    private Feed_Profiles $profiles;
    private Feed_Logs $logs;
    private Feed_Exporter $exporter;

    public function __construct( Feed_Profiles $profiles, Feed_Logs $logs, Feed_Exporter $exporter ) {
        $this->profiles = $profiles;
        $this->logs     = $logs;
        $this->exporter = $exporter;
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
        $profiles          = $this->profiles->all();
        $logs              = $this->logs->latest();
        $paths             = apm_get_feed_paths();
        $manual            = $this->get_manual_steps();
        $schedule_options  = Feed_Scheduler::get_schedule_choices();
        include APM_PLUGIN_DIR . 'includes/views/feed-manager-page.php';
    }

    public function handle_save_profile() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_feed_profile' );
        $profile = $this->profiles->save( $_POST );

        $notice = $profile ? __( 'Profilo salvato.', 'advanced-promo-mechanics' ) : __( 'Errore salvataggio profilo.', 'advanced-promo-mechanics' );
        if ( $profile ) {
            $result = $this->exporter->generate_for_profile( $profile );
            if ( $result['success'] ) {
                $notice .= ' ' . sprintf(
                    /* translators: 1: feed url */
                    __( 'Feed aggiornato: %s', 'advanced-promo-mechanics' ),
                    esc_url_raw( $result['file_url'] ?? '' )
                );
                apm_log_activity( 'feed_profile_saved', __( 'Profilo feed salvato.', 'advanced-promo-mechanics' ), [ 'profile_id' => $profile['id'] ?? null, 'name' => $profile['name'] ?? '', 'schedule' => $profile['schedule'] ?? 'manual', 'feed_url' => $result['file_url'] ?? '' ] );
            } else {
                $notice .= ' ' . sanitize_text_field( $result['message'] );
                apm_log_activity( 'feed_profile_save_error', __( 'Errore durante la generazione del feed.', 'advanced-promo-mechanics' ), [ 'profile_id' => $profile['id'] ?? null, 'error' => $result['message'] ?? '' ] );
            }
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-feed-manager', 'apm_notice' => rawurlencode( $notice ) ], admin_url( 'admin.php' ) ) );
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
            apm_log_activity( 'feed_profile_deleted', __( 'Profilo feed eliminato.', 'advanced-promo-mechanics' ), [ 'profile_id' => $id ] );
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
