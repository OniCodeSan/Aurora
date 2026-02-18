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
    private const OPTION_MERCHANTS = 'aurora_feed_receiver_merchants';
    private const OPTION_FIELD_MAP = 'aurora_feed_receiver_field_map';
    private const OPTION_FORCE_FLAGS = 'aurora_feed_receiver_force_flags';
    private const OPTION_INTERVAL = 'aurora_feed_receiver_interval';
    private const TRANSIENT_FIELDS = 'aurora_feed_receiver_field_cache';
    private const TRANSIENT_MANUAL_NOTICE = 'aurora_feed_receiver_manual_notice';
    private const ACTION_HOOK = 'aurora_feed_receiver_run';
    private const META_EXTERNAL_ID = '_aurora_external_id';

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
        add_action( 'admin_post_aurora_feed_receiver_manual', [ $this, 'handle_manual_trigger' ] );
        add_action( self::ACTION_HOOK, [ $this, 'run' ] );

        if ( ! get_option( self::OPTION_INTERVAL, false ) ) {
            update_option( self::OPTION_INTERVAL, self::get_default_interval_slug(), false );
        }

        if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
            self::schedule_recurring();
        }
    }

    public static function activate() : void {
        if ( ! get_option( self::OPTION_INTERVAL, false ) ) {
            update_option( self::OPTION_INTERVAL, self::get_default_interval_slug(), false );
        }
        self::schedule_recurring();
    }

    public static function deactivate() : void {
        self::clear_events();
    }

    private static function schedule_recurring( ?string $interval_slug = null ) : void {
        self::clear_events();
        $interval = $interval_slug ?: self::get_current_interval_slug();

        $schedules = wp_get_schedules();
        $custom    = self::get_interval_definitions();
        if ( isset( $custom[ $interval ] ) && ! isset( $schedules[ $interval ] ) ) {
            $schedules[ $interval ] = $custom[ $interval ];
        }
        if ( ! isset( $schedules[ $interval ] ) ) {
            $interval = 'hourly';
        }

        wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::ACTION_HOOK );
    }

    private static function clear_events() : void {
        $timestamp = wp_next_scheduled( self::ACTION_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::ACTION_HOOK );
            $timestamp = wp_next_scheduled( self::ACTION_HOOK );
        }
    }

    public function run() : void {
        $this->record_fetch();
    }

    /**
     * Esegue un fetch e salva nel log.
     *
     * @param string|null $override_url URL alternativo per questa chiamata.
     * @return array<string,mixed>|null
     */
    public function record_fetch( ?string $override_url = null ) : ?array {
        $url = $override_url ? trim( $override_url ) : trim( (string) get_option( self::OPTION_URL, '' ) );
        if ( empty( $url ) ) {
            return null;
        }

        $entry = $this->fetch_once( $url );
        if ( ! $entry ) {
            return null;
        }

        $body = $entry['body'] ?? '';
        if ( ! empty( $body ) ) {
            $stats = $this->process_feed_payload( $body );
            if ( $stats && ( $stats['processed'] ?? 0 ) > 0 ) {
                $entry['message'] = sprintf(
                    /* translators: 1: processed rows, 2: created rows, 3: updated rows */
                    __( 'Importati %1$d record (%2$d nuovi, %3$d aggiornati).', 'aurora-feed-receiver' ),
                    (int) $stats['processed'],
                    (int) $stats['created'],
                    (int) $stats['updated']
                );
            }
        }

        unset( $entry['body'] );

        $log = get_option( self::OPTION_LOG, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 10 );
        update_option( self::OPTION_LOG, $log, false );
        return $entry;
    }

    /**
     * Effettua un singolo fetch, senza toccare il log.
     *
     * @param string $url Feed URL.
     * @return array<string,mixed>
     */
    private function fetch_once( string $url ) : array {
        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json, application/xml;q=0.9, */*;q=0.8',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'time'    => current_time( 'mysql' ),
                'status'  => 'error',
                'message' => $response->get_error_message(),
            ];
        }

        $body    = wp_remote_retrieve_body( $response );
        $status  = (int) wp_remote_retrieve_response_code( $response );
        $snippet = wp_trim_words( trim( wp_strip_all_tags( $body ) ), 40, '…' );

        return [
            'time'    => current_time( 'mysql' ),
            'status'  => $status,
            'message' => $snippet,
            'body'    => $body,
        ];
    }


    /**
     * Processa il payload del feed e aggiorna/crea i prodotti WooCommerce.
     *
     * @param string $payload Feed grezzo.
     * @return array<string,int>
     */
    private function process_feed_payload( string $payload ) : array {
        $payload = trim( $payload );
        if ( '' === $payload ) {
            return [ 'processed' => 0, 'created' => 0, 'updated' => 0 ];
        }

        $items = $this->parse_feed_payload( $payload );
        if ( empty( $items ) ) {
            return [ 'processed' => 0, 'created' => 0, 'updated' => 0 ];
        }

        $stats = [ 'processed' => 0, 'created' => 0, 'updated' => 0 ];
        foreach ( $items as $item ) {
            if ( empty( array_filter( $item, static fn( $value ) => null !== $value && '' !== $value ) ) ) {
                continue;
            }
            $stats['processed']++;
            $result = $this->upsert_product( $item );
            if ( 'created' === $result ) {
                $stats['created']++;
            } elseif ( 'updated' === $result ) {
                $stats['updated']++;
            }
        }

        return $stats;
    }

    /**
     * Determina il formato del feed e restituisce un array di righe associative.
     *
     * @param string $payload Payload grezzo.
     * @return array<int,array<string,mixed>>
     */
    private function parse_feed_payload( string $payload ) : array {
        $first_char = substr( ltrim( $payload ), 0, 1 );
        if ( '<' === $first_char ) {
            return $this->parse_xml_feed( $payload );
        }

        return $this->parse_csv_feed( $payload );
    }

    /**
     * Parsing CSV.
     *
     * @param string $payload CSV grezzo.
     * @return array<int,array<string,mixed>>
     */
    private function parse_csv_feed( string $payload ) : array {
        $data = [];
        $delimiter = $this->detect_csv_delimiter( $payload );
        $handle = fopen( 'php://temp', 'r+' );
        if ( false === $handle ) {
            return $data;
        }
        fwrite( $handle, $payload );
        rewind( $handle );

        $headers = fgetcsv( $handle, 0, $delimiter );
        if ( false === $headers ) {
            fclose( $handle );
            return $data;
        }
        $headers = array_map( [ $this, 'sanitize_field_key' ], $headers );

        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $assoc = [];
            foreach ( $headers as $index => $key ) {
                $assoc[ $key ] = $row[ $index ] ?? null;
            }
            $data[] = $this->normalize_item( $assoc );
        }

        fclose( $handle );
        return $data;
    }

    /**
     * Parsing XML con nodi <product> o <item>.
     *
     * @param string $payload XML grezzo.
     * @return array<int,array<string,mixed>>
     */
    private function parse_xml_feed( string $payload ) : array {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $payload, 'SimpleXMLElement', LIBXML_NOCDATA );
        if ( false === $xml ) {
            libxml_clear_errors();
            return [];
        }
        $nodes = $xml->xpath( '//product' );
        if ( false === $nodes || empty( $nodes ) ) {
            $nodes = $xml->xpath( '//item' );
        }
        if ( false === $nodes || empty( $nodes ) ) {
            return [];
        }

        $data = [];
        foreach ( $nodes as $node ) {
            $decoded = json_decode( wp_json_encode( $node ), true );
            if ( is_array( $decoded ) ) {
                $data[] = $this->normalize_item( $decoded );
            }
        }
        return $data;
    }

    /**
     * Normalizza una riga del feed.
     *
     * @param array<string,mixed> $item Riga feed.
     * @return array<string,mixed>
     */
    private function normalize_item( array $item ) : array {
        $normalized = [];
        foreach ( $item as $key => $value ) {
            $key = $this->sanitize_field_key( (string) $key );
            if ( is_string( $value ) ) {
                $value = trim( $value );
            }
            $normalized[ $key ] = $value;
        }

        if ( isset( $normalized['product_id'] ) && ! isset( $normalized['id'] ) ) {
            $normalized['id'] = $normalized['product_id'];
        }
        if ( isset( $normalized['quantity'] ) && ! isset( $normalized['stock_quantity'] ) ) {
            $normalized['stock_quantity'] = $normalized['quantity'];
        }

        return $normalized;
    }

    /**
     * Inserisce o aggiorna un prodotto in base ai dati del feed.
     *
     * @param array<string,mixed> $item Riga normalizzata.
     * @return string|null created|updated|null
     */
    private function upsert_product( array $item ) : ?string {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return null;
        }

        $external_id = isset( $item['id'] ) ? (string) $item['id'] : null;
        $sku         = isset( $item['sku'] ) ? (string) $item['sku'] : ( $external_id ?? null );

        if ( empty( $sku ) && empty( $external_id ) ) {
            return null;
        }

        $product_id = $sku ? (int) wc_get_product_id_by_sku( $sku ) : 0;
        if ( ! $product_id && $external_id ) {
            $product_id = $this->get_product_id_by_external( $external_id );
        }

        if ( $product_id ) {
            return $this->update_existing_product( $product_id, $item ) ? 'updated' : null;
        }

        $created_id = $this->create_new_product( $item, $sku, $external_id );
        return $created_id ? 'created' : null;
    }

    /**
     * Restituisce l'ID prodotto legato all'ID esterno.
     */
    private function get_product_id_by_external( string $external_id ) : int {
        global $wpdb;
        $meta_key = self::META_EXTERNAL_ID;
        $query    = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $external_id
        );
        $result = $wpdb->get_var( $query );
        return $result ? (int) $result : 0;
    }

    /**
     * Aggiorna solo stock e prezzo per i prodotti esistenti.
     */
    private function update_existing_product( int $product_id, array $item ) : bool {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $updated = false;

        if ( isset( $item['stock_quantity'] ) || isset( $item['stock'] ) ) {
            $quantity = $item['stock_quantity'] ?? $item['stock'];
            $quantity = is_numeric( $quantity ) ? (float) $quantity : null;
            if ( null !== $quantity ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( wc_stock_amount( $quantity ) );
                $updated = true;
            }
        }

        $price = $item['regular_price'] ?? $item['price'] ?? null;
        if ( null !== $price && is_numeric( $price ) ) {
            $product->set_regular_price( wc_format_decimal( $price ) );
            $updated = true;
        }

        if ( isset( $item['sale_price'] ) && is_numeric( $item['sale_price'] ) ) {
            $product->set_sale_price( wc_format_decimal( $item['sale_price'] ) );
            $updated = true;
        }

        if ( $updated ) {
            $product->save();
        }

        return $updated;
    }

    /**
     * Crea un nuovo prodotto con tutti i campi disponibili.
     */
    private function create_new_product( array $item, ?string $sku, ?string $external_id ) : ?int {
        $title = $item['name'] ?? $item['title'] ?? ( $sku ? sprintf( 'Prodotto %s', $sku ) : __( 'Prodotto importato', 'aurora-feed-receiver' ) );
        $description = $item['description'] ?? $item['content'] ?? '';

        $postarr = [
            'post_title'   => wp_strip_all_tags( $title ),
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ];

        $product_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $product_id ) || ! $product_id ) {
            return null;
        }

        if ( $sku ) {
            update_post_meta( $product_id, '_sku', sanitize_text_field( $sku ) );
        }
        if ( $external_id ) {
            update_post_meta( $product_id, self::META_EXTERNAL_ID, sanitize_text_field( $external_id ) );
        }

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $regular_price = $item['regular_price'] ?? $item['price'] ?? null;
            if ( null !== $regular_price && is_numeric( $regular_price ) ) {
                $product->set_regular_price( wc_format_decimal( $regular_price ) );
            }
            if ( isset( $item['sale_price'] ) && is_numeric( $item['sale_price'] ) ) {
                $product->set_sale_price( wc_format_decimal( $item['sale_price'] ) );
            }
            if ( isset( $item['stock_quantity'] ) || isset( $item['stock'] ) ) {
                $quantity = $item['stock_quantity'] ?? $item['stock'];
                if ( is_numeric( $quantity ) ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( wc_stock_amount( $quantity ) );
                }
            }
            $product->save();
        }

        return $product_id;
    }

    /**
     * Sanifica l'intestazione.
     */
    private function sanitize_field_key( string $key ) : string {
        $key = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $key ) );
        return trim( $key, '_' );
    }

    /**
     * Prova a indovinare il delimitatore del CSV.
     */
    private function detect_csv_delimiter( string $sample ) : string {
        $delimiters = [ ';', ',', "	" ];
        $best_delimiter = ',';
        $best_count     = 0;
        foreach ( $delimiters as $delimiter ) {
            $count = substr_count( $sample, $delimiter );
            if ( $count > $best_count ) {
                $best_count     = $count;
                $best_delimiter = $delimiter;
            }
        }
        return $best_delimiter;
    }

    /**
     * Elenco intervalli disponibili.
     *
     * @return array<string,array<string,int|string>>
     */
    private static function get_interval_definitions() : array {
        return [
            'aurora_15min' => [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Ogni 15 minuti', 'aurora-feed-receiver' ) ],
            'aurora_30min' => [ 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __( 'Ogni 30 minuti', 'aurora-feed-receiver' ) ],
            'aurora_1h'    => [ 'interval' => HOUR_IN_SECONDS,        'display' => __( 'Ogni ora', 'aurora-feed-receiver' ) ],
            'aurora_3h'    => [ 'interval' => 3 * HOUR_IN_SECONDS,    'display' => __( 'Ogni 3 ore', 'aurora-feed-receiver' ) ],
            'aurora_6h'    => [ 'interval' => 6 * HOUR_IN_SECONDS,    'display' => __( 'Ogni 6 ore', 'aurora-feed-receiver' ) ],
            'aurora_12h'   => [ 'interval' => 12 * HOUR_IN_SECONDS,   'display' => __( 'Ogni 12 ore', 'aurora-feed-receiver' ) ],
            'aurora_24h'   => [ 'interval' => DAY_IN_SECONDS,         'display' => __( 'Ogni 24 ore', 'aurora-feed-receiver' ) ],
        ];
    }

    private static function get_default_interval_slug() : string {
        return 'aurora_30min';
    }

    private static function get_current_interval_slug() : string {
        $stored = get_option( self::OPTION_INTERVAL, self::get_default_interval_slug() );
        $definitions = self::get_interval_definitions();
        if ( empty( $stored ) || ! isset( $definitions[ $stored ] ) ) {
            $stored = self::get_default_interval_slug();
        }
        return $stored;
    }

    private static function normalize_interval_slug( string $slug ) : string {
        $slug = sanitize_key( $slug );
        $definitions = self::get_interval_definitions();
        if ( ! isset( $definitions[ $slug ] ) ) {
            $slug = self::get_default_interval_slug();
        }
        return $slug;
    }

    private function get_interval_choices() : array {
        $definitions = self::get_interval_definitions();
        $choices = [];
        foreach ( $definitions as $key => $def ) {
            $choices[ $key ] = (string) ( $def['display'] ?? $key );
        }
        return $choices;
    }

    /**
     * Restituisce i merchant configurati.
    /**
     * Sanifica l'identificatore tabella.colonna.
     */
    private function sanitize_field_identifier( string $field ) : string {
        $field = trim( $field );
        if ( '' === $field ) {
            return '';
        }
        $parts = explode( '.', $field );
        if ( count( $parts ) < 2 ) {
            $table = preg_replace( '/[^a-zA-Z0-9_$]+/', '', $field );
            return $table ?: '';
        }
        $table  = preg_replace( '/[^a-zA-Z0-9_$]+/', '', array_shift( $parts ) );
        $column = preg_replace( '/[^a-zA-Z0-9_$]+/', '', implode( '.', $parts ) );
        if ( '' === $table || '' === $column ) {
            return '';
        }
        return $table . '.' . $column;
    }

    /**
     * Restituisce i merchant configurati.
     *
     * @return array<string,string> key => label
     */
    private function get_merchants() : array {
        $stored = get_option( self::OPTION_MERCHANTS, [] );
        if ( ! is_array( $stored ) || empty( $stored ) ) {
            $stored = $this->get_default_merchants();
        }
        return $stored;
    }

    /**
     * Merchant di default.
     *
     * @return array<string,string>
     */
    private function get_default_merchants() : array {
        return [
            'ebay'   => 'eBay',
            'amazon' => 'Amazon',
            'etsy'   => 'Etsy',
        ];
    }

    /**
     * Genera la chiave normalizzata per il merchant.
     */
    private function normalize_merchant_key( string $label ) : string {
        $key = sanitize_title( $label );
        if ( '' === $key ) {
            $key = sanitize_title( uniqid( 'merchant_', true ) );
        }
        return $key;
    }

    /**
     * Restituisce i flag forza immagine/contenuto per merchant.
     *
     * @return array<string,array<string,bool>>
     */
    private function get_force_flags() : array {
        $flags = get_option( self::OPTION_FORCE_FLAGS, [] );
        if ( ! is_array( $flags ) ) {
            $flags = [];
        }
        return $flags;
    }

    /**
     * Recupera le colonne disponibili dalle tabelle prodotto/catalogo.
     *
     * @return array<string,array<string,string>> gruppo => [ field_key => label ]
     */
    private function get_available_field_groups() : array {
        $cached = get_transient( self::TRANSIENT_FIELDS );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $tables = [
            __( 'Prodotti (wp_posts)', 'aurora-feed-receiver' )             => $wpdb->posts,
            __( 'Meta prodotto (wp_postmeta)', 'aurora-feed-receiver' )      => $wpdb->postmeta,
            __( 'Termini (wp_terms)', 'aurora-feed-receiver' )               => $wpdb->terms,
            __( 'Meta termini (wp_termmeta)', 'aurora-feed-receiver' )       => $wpdb->termmeta,
            __( 'Gerarchie (wp_term_taxonomy)', 'aurora-feed-receiver' )     => $wpdb->term_taxonomy,
            __( 'Relazioni (wp_term_relationships)', 'aurora-feed-receiver' ) => $wpdb->term_relationships,
            __( 'Attributi WooCommerce', 'aurora-feed-receiver' )            => $wpdb->prefix . 'woocommerce_attribute_taxonomies',
        ];

        $groups = [];
        foreach ( $tables as $group_label => $table_name ) {
            if ( empty( $table_name ) ) {
                continue;
            }
            $columns = $wpdb->get_results( "DESCRIBE `{$table_name}`", ARRAY_A );
            if ( empty( $columns ) ) {
                continue;
            }
            foreach ( $columns as $column ) {
                if ( empty( $column['Field'] ) ) {
                    continue;
                }
                $field_key = $table_name . '.' . $column['Field'];
                $groups[ $group_label ][ $field_key ] = $column['Field'];
            }
        }

        set_transient( self::TRANSIENT_FIELDS, $groups, HOUR_IN_SECONDS );
        return $groups;
    }

    /**
     * Registra gli intervalli custom nel cron di WordPress.
     *
     * @param array<string,mixed> $schedules
     * @return array<string,mixed>
     */
    public static function register_schedules( array $schedules ) : array {
        foreach ( self::get_interval_definitions() as $key => $definition ) {
            $schedules[ $key ] = $definition;
        }
        return $schedules;
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

        $url               = esc_url_raw( (string) get_option( self::OPTION_URL, '' ) );
        $log               = get_option( self::OPTION_LOG, [] );
        $merchants         = $this->get_merchants();
        $field_map         = $this->get_field_map();
        $force_flags       = $this->get_force_flags();
        $field_groups      = $this->get_available_field_groups();
        $interval_choices  = $this->get_interval_choices();
        $current_interval  = self::get_current_interval_slug();
        $manual_notice     = get_transient( self::TRANSIENT_MANUAL_NOTICE );
        if ( false !== $manual_notice && ! is_array( $manual_notice ) ) {
            $manual_notice = null;
        } elseif ( is_array( $manual_notice ) ) {
            delete_transient( self::TRANSIENT_MANUAL_NOTICE );
        }
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Aurora Feed Receiver', 'aurora-feed-receiver' ); ?></h1>
            <p><?php esc_html_e( 'Indica l\'URL del feed prodotti. Il sistema effettuerà un polling ricorrente in base all\'intervallo selezionato.', 'aurora-feed-receiver' ); ?></p>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni aggiornate e polling riavviato.', 'aurora-feed-receiver' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $manual_notice ) : 
                $notice_class = 'notice ' . ( ( $manual_notice['type'] ?? '' ) === 'error' ? 'notice-error' : 'notice-success' ); ?>
                <div class="<?php echo esc_attr( $notice_class ); ?> is-dismissible"><p><?php echo esc_html( $manual_notice['message'] ?? '' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'aurora_feed_receiver_save', 'aurora_feed_receiver_nonce' ); ?>
                <input type="hidden" name="action" value="aurora_feed_receiver_save" />
                <input type="hidden" name="aurora_feed_section" value="url" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="aurora-feed-url"><?php esc_html_e( 'URL feed', 'aurora-feed-receiver' ); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" name="aurora_feed_url" id="aurora-feed-url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com/feed.json" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aurora-feed-interval"><?php esc_html_e( 'Frequenza aggiornamento', 'aurora-feed-receiver' ); ?></label></th>
                        <td>
                            <select name="aurora_feed_interval" id="aurora-feed-interval">
                                <?php foreach ( $interval_choices as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_interval, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Scegli ogni quanto il feed deve essere interrogato automaticamente.', 'aurora-feed-receiver' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Salva impostazioni', 'aurora-feed-receiver' ) ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aurora-manual-run">
                <?php wp_nonce_field( 'aurora_feed_receiver_manual', 'aurora_feed_receiver_manual_nonce' ); ?>
                <input type="hidden" name="action" value="aurora_feed_receiver_manual" />
                <?php submit_button( __( 'Esegui aggiornamento adesso', 'aurora-feed-receiver' ), 'secondary' ); ?>
            </form>

            <h2><?php esc_html_e( 'Mappatura campi per merchant', 'aurora-feed-receiver' ); ?></h2>
            <p><?php esc_html_e( 'Scegli i campi del catalogo WooCommerce da esporre per ciascun marketplace.', 'aurora-feed-receiver' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'aurora_feed_receiver_save', 'aurora_feed_receiver_nonce' ); ?>
                <input type="hidden" name="action" value="aurora_feed_receiver_save" />
                <input type="hidden" name="aurora_feed_section" value="mapping" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Elenco merchant', 'aurora-feed-receiver' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Uno per riga. Verranno create le relative schede di mappatura.', 'aurora-feed-receiver' ); ?></p>
                            <textarea name="aurora_merchants" rows="3" class="large-text"><?php echo esc_textarea( implode( "
", array_values( $merchants ) ) ); ?></textarea>
                        </td>
                    </tr>
                </table>
                <div class="aurora-field-map">
                    <?php if ( empty( $field_groups ) ) : ?>
                        <p><?php esc_html_e( 'Impossibile leggere la struttura delle tabelle. Controlla i permessi del database.', 'aurora-feed-receiver' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $merchants as $merchant_key => $merchant_label ) : 
                            $selected = $field_map[ $merchant_key ] ?? [];
                            $force    = $force_flags[ $merchant_key ] ?? [ 'image' => false, 'content' => false ]; ?>
                            <fieldset class="merchant-panel">
                                <legend><?php echo esc_html( $merchant_label ); ?></legend>
                                <div class="force-options">
                                    <label>
                                        <input type="checkbox" name="aurora_force_image[<?php echo esc_attr( $merchant_key ); ?>]" value="1" <?php checked( ! empty( $force['image'] ) ); ?> />
                                        <span><?php esc_html_e( 'Forza immagine', 'aurora-feed-receiver' ); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" name="aurora_force_content[<?php echo esc_attr( $merchant_key ); ?>]" value="1" <?php checked( ! empty( $force['content'] ) ); ?> />
                                        <span><?php esc_html_e( 'Forza contenuto', 'aurora-feed-receiver' ); ?></span>
                                    </label>
                                </div>
                                <?php foreach ( $field_groups as $group_label => $fields ) : ?>
                                    <details class="group" open>
                                        <summary><?php echo esc_html( $group_label ); ?></summary>
                                        <div class="group-fields">
                                            <?php foreach ( $fields as $field_key => $field_label ) : 
                                                $field_id = 'field-' . $merchant_key . '-' . sanitize_title( $field_key ); ?>
                                                <label for="<?php echo esc_attr( $field_id ); ?>" class="field-option">
                                                    <input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="aurora_field_map[<?php echo esc_attr( $merchant_key ); ?>][]" value="<?php echo esc_attr( $field_key ); ?>" <?php checked( in_array( $field_key, $selected, true ) ); ?> />
                                                    <span><?php echo esc_html( $field_label ); ?></span>
                                                    <code><?php echo esc_html( $field_key ); ?></code>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php submit_button( __( 'Salva mappature', 'aurora-feed-receiver' ), 'secondary' ); ?>
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
        $section = isset( $_POST['aurora_feed_section'] ) ? sanitize_key( wp_unslash( $_POST['aurora_feed_section'] ) ) : 'url';
        $redirect_args = [ 'page' => 'aurora-feed-receiver', 'updated' => 1, 'section' => $section ];

        if ( 'mapping' === $section ) {
            $merchants_raw = isset( $_POST['aurora_merchants'] ) ? wp_unslash( $_POST['aurora_merchants'] ) : '';
            $lines = preg_split( '/[
]+/', (string) $merchants_raw );
            $merchants = [];
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $label = trim( $line );
                    if ( '' === $label ) {
                        continue;
                    }
                    $key         = $this->normalize_merchant_key( $label );
                    $base_key    = $key;
                    $dedupe_iter = 2;
                    while ( '' !== $key && isset( $merchants[ $key ] ) ) {
                        $key = $base_key . '-' . $dedupe_iter;
                        $dedupe_iter++;
                    }
                    if ( '' === $key ) {
                        continue;
                    }
                    $merchants[ $key ] = $label;
                }
            }
            if ( empty( $merchants ) ) {
                $merchants = $this->get_default_merchants();
            }


            $raw_map = isset( $_POST['aurora_field_map'] ) ? wp_unslash( $_POST['aurora_field_map'] ) : [];
            $normalized_map = [];
            if ( ! is_array( $raw_map ) ) {
                $raw_map = [];
            }

            $force_image   = isset( $_POST['aurora_force_image'] ) ? wp_unslash( $_POST['aurora_force_image'] ) : [];
            $force_content = isset( $_POST['aurora_force_content'] ) ? wp_unslash( $_POST['aurora_force_content'] ) : [];
            if ( ! is_array( $force_image ) ) {
                $force_image = [];
            }
            if ( ! is_array( $force_content ) ) {
                $force_content = [];
            }
            $force_flags = [];

            foreach ( $merchants as $merchant_key => $merchant_label ) {
                $selected = $raw_map[ $merchant_key ] ?? [];
                if ( ! is_array( $selected ) ) {
                    $selected = [];
                }
                $clean = [];
                foreach ( $selected as $field_key ) {
                    $field_key = $this->sanitize_field_identifier( (string) $field_key );
                    if ( '' === $field_key || in_array( $field_key, $clean, true ) ) {
                        continue;
                    }
                    $clean[] = $field_key;
                }
                $normalized_map[ $merchant_key ] = $clean;

                $force_flags[ $merchant_key ] = [
                    'image'   => ! empty( $force_image[ $merchant_key ] ),
                    'content' => ! empty( $force_content[ $merchant_key ] ),
                ];
            }

            update_option( self::OPTION_MERCHANTS, $merchants, false );
            update_option( self::OPTION_FIELD_MAP, $normalized_map, false );
            update_option( self::OPTION_FORCE_FLAGS, $force_flags, false );
        } else {
            $url = isset( $_POST['aurora_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['aurora_feed_url'] ) ) : '';
            update_option( self::OPTION_URL, $url, false );

            $interval_input = isset( $_POST['aurora_feed_interval'] ) ? wp_unslash( $_POST['aurora_feed_interval'] ) : self::get_default_interval_slug();
            $interval_slug  = self::normalize_interval_slug( (string) $interval_input );
            update_option( self::OPTION_INTERVAL, $interval_slug, false );

            self::schedule_recurring( $interval_slug );
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_manual_trigger() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'aurora-feed-receiver' ) );
        }
        check_admin_referer( 'aurora_feed_receiver_manual', 'aurora_feed_receiver_manual_nonce' );

        $entry = $this->record_fetch();
        if ( null === $entry ) {
            $notice = [
                'type'    => 'error',
                'message' => __( 'Polling manuale non eseguito: nessun URL configurato.', 'aurora-feed-receiver' ),
            ];
        } else {
            $status_code = isset( $entry['status'] ) ? (int) $entry['status'] : null;
            $message     = isset( $entry['message'] ) ? (string) $entry['message'] : __( 'Vedi log per dettagli.', 'aurora-feed-receiver' );
            $is_success  = $status_code && $status_code >= 200 && $status_code < 400;
            $notice = [
                'type'    => $is_success ? 'success' : 'error',
                'message' => sprintf(
                    __( 'Polling manuale completato (status %1$s). %2$s', 'aurora-feed-receiver' ),
                    $status_code ?: 'n/a',
                    $message
                ),
            ];
        }

        set_transient( self::TRANSIENT_MANUAL_NOTICE, $notice, MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-feed-receiver', 'manual' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }
}

add_filter( 'cron_schedules', [ 'Aurora_Feed_Receiver', 'register_schedules' ] );
add_action( 'plugins_loaded', [ 'Aurora_Feed_Receiver', 'instance' ] );
register_activation_hook( __FILE__, [ 'Aurora_Feed_Receiver', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Aurora_Feed_Receiver', 'deactivate' ] );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class Aurora_Feed_Receiver_CLI extends WP_CLI_Command {
        /**
         * Poll del feed prodotti.
         *
         * ## OPTIONS
         *
         * [--url=<url>]
         * : URL alternativo da interrogare per questa esecuzione.
         */
        public function poll( array $args, array $assoc_args ) : void {
            $url = $assoc_args['url'] ?? null;
            $receiver = Aurora_Feed_Receiver::instance();
            $entry = $receiver->record_fetch( $url );
            if ( null === $entry ) {
                WP_CLI::warning( __( 'Nessun URL configurato.', 'aurora-feed-receiver' ) );
                return;
            }
            $status = $entry['status'] ?? 'n/a';
            $message = $entry['message'] ?? '';
            WP_CLI::success( sprintf( 'Status %s – %s', $status, $message ) );
        }
    }
    WP_CLI::add_command( 'aurora feed', 'Aurora_Feed_Receiver_CLI' );
}
