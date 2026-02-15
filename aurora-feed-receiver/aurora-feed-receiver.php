<?php
/**
 * Plugin Name:       Aurora Feed Receiver
 * Description:       Riceve il feed prodotti tramite polling ogni minuto per i prossimi 10 minuti e conserva un log degli ultimi tentativi.
 * Version:           0.1.0
 * Author:            Mariano + Mark
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Aurora_Feed_Receiver {
    private const OPTION_URL = 'aurora_feed_receiver_url';
    private const OPTION_LOG = 'aurora_feed_receiver_log';
    private const ACTION_HOOK = 'aurora_feed_receiver_run';

    private static ?self $instance = null;

    public static function instance() : self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_aurora_feed_receiver_save', [ $this, 'save_settings' ] );
        add_action( self::ACTION_HOOK, [ $this, 'run' ] );

        if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
            self::schedule_window();
        }
    }

    public static function activate() : void {
        self::schedule_window();
    }

    public static function deactivate() : void {
        self::clear_events();
    }

    private static function schedule_window() : void {
        self::clear_events();
        $start = time();
        for ( $i = 0; $i < 10; $i++ ) {
            wp_schedule_single_event( $start + ( $i * MINUTE_IN_SECONDS ), self::ACTION_HOOK );
        }
    }

    private static function clear_events() : void {
        $timestamp = wp_next_scheduled( self::ACTION_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::ACTION_HOOK );
            $timestamp = wp_next_scheduled( self::ACTION_HOOK );
        }
    }

    public function run() : void {
        $url = trim( (string) get_option( self::OPTION_URL, '' ) );
        if ( empty( $url ) ) {
            return;
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json, application/xml;q=0.9, */*;q=0.8',
            ],
        ] );

        $log = get_option( self::OPTION_LOG, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        if ( is_wp_error( $response ) ) {
            $entry = [
                'time'    => current_time( 'mysql' ),
                'status'  => 'error',
                'message' => $response->get_error_message(),
            ];
        } else {
            $body    = wp_remote_retrieve_body( $response );
            $status  = (int) wp_remote_retrieve_response_code( $response );
            $snippet = wp_trim_words( trim( wp_strip_all_tags( $body ) ), 40, '…' );
            $entry   = [
                'time'    => current_time( 'mysql' ),
                'status'  => $status,
                'message' => $snippet,
            ];
        }

        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 10 );
        update_option( self::OPTION_LOG, $log, false );
    }

    public function register_menu() : void {
        add_submenu_page(
            'aurora-project',
            __( 'Feed Receiver', 'aurora-feed-receiver' ),
            __( 'Feed Receiver', 'aurora-feed-receiver' ),
            'manage_woocommerce',
            'aurora-feed-receiver',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $url = esc_url_raw( (string) get_option( self::OPTION_URL, '' ) );
        $log = get_option( self::OPTION_LOG, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Aurora Feed Receiver', 'aurora-feed-receiver' ); ?></h1>
            <p><?php esc_html_e( 'Indica l\'URL del feed prodotti. Il sistema effettuerà un polling ogni minuto per i prossimi 10 minuti.', 'aurora-feed-receiver' ); ?></p>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni aggiornate e polling riavviato.', 'aurora-feed-receiver' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'aurora_feed_receiver_save', 'aurora_feed_receiver_nonce' ); ?>
                <input type="hidden" name="action" value="aurora_feed_receiver_save" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="aurora-feed-url"><?php esc_html_e( 'URL feed', 'aurora-feed-receiver' ); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" name="aurora_feed_url" id="aurora-feed-url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com/feed.json" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Salva e avvia polling (10 min)', 'aurora-feed-receiver' ) ); ?>
            </form>
            <h2><?php esc_html_e( 'Ultimi tentativi', 'aurora-feed-receiver' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Orario', 'aurora-feed-receiver' ); ?></th>
                        <th><?php esc_html_e( 'Stato', 'aurora-feed-receiver' ); ?></th>
                        <th><?php esc_html_e( 'Messaggio', 'aurora-feed-receiver' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $log ) ) : ?>
                        <tr><td colspan="3"><?php esc_html_e( 'Ancora nessuna richiesta registrata.', 'aurora-feed-receiver' ); ?></td></tr>
                    <?php else : foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $entry['status'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function save_settings() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'aurora-feed-receiver' ) );
        }
        check_admin_referer( 'aurora_feed_receiver_save', 'aurora_feed_receiver_nonce' );
        $url = isset( $_POST['aurora_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['aurora_feed_url'] ) ) : '';
        update_option( self::OPTION_URL, $url, false );
        self::schedule_window();
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-feed-receiver', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }
}

add_action( 'plugins_loaded', [ 'Aurora_Feed_Receiver', 'instance' ] );
register_activation_hook( __FILE__, [ 'Aurora_Feed_Receiver', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Aurora_Feed_Receiver', 'deactivate' ] );
