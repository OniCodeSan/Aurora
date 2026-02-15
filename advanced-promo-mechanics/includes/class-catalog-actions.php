<?php
namespace APM;

use Throwable;
use WC_Product;
use WP_Error;
use WP_Query;

class Catalog_Actions {
    private License $license;
    private int $batch_size = 100;
    private const SELECTION_TTL = DAY_IN_SECONDS;
    private const JOB_TTL        = DAY_IN_SECONDS;

    public function __construct( License $license ) {
        $this->license = $license;
    }

    public function init() : void {
        add_action( 'admin_post_apm_catalog_action', [ $this, 'handle_form' ] );
        add_action( 'admin_post_apm_export_full_catalog', [ $this, 'handle_full_catalog_export' ] );
        add_filter( 'bulk_actions-edit-product', [ $this, 'register_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'restrict_manage_posts', [ $this, 'render_bulk_hidden_fields' ], 20, 2 );
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        add_action( 'admin_notices', [ $this, 'render_job_progress_notices' ], 30 );
        add_filter( 'edit_product_per_page', [ $this, 'set_products_per_page' ] );
        add_action( 'wp_ajax_apm_select_all_command', [ $this, 'handle_select_all_ajax' ] );
        add_action( 'apm_run_bulk_job', [ $this, 'process_bulk_job' ] );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
        if ( is_wp_error( $categories ) ) {
            $categories = [];
        }
        $notice         = isset( $_GET['apm_catalog_notice'] ) ? wp_unslash( $_GET['apm_catalog_notice'] ) : '';// phpcs:ignore
        $license_active = $this->license->is_active();
        $status_message = $this->license->get_status_message();
        $license_key    = $this->license->get_license_key();
        include APM_PLUGIN_DIR . 'includes/views/catalog-actions-page.php';
    }

    public function handle_form() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_catalog_action' );

        if ( ! $this->license->is_active() ) {
            $this->redirect_with_notice( __( 'Funzionalità disponibile solo con licenza attiva.', 'advanced-promo-mechanics' ) );
        }

        $action     = sanitize_key( $_POST['apm_action'] ?? '' );
        $category   = isset( $_POST['apm_target_category'] ) ? absint( $_POST['apm_target_category'] ) : 0;
        $csv_cols   = isset( $_POST['apm_csv_fields'] ) ? array_map( 'sanitize_key', (array) $_POST['apm_csv_fields'] ) : [];
        $filters    = $this->parse_filters();
        $ids        = $this->get_product_ids( $filters );
        $affected   = 0;
        $message    = __( 'Nessun prodotto trovato per i filtri selezionati.', 'advanced-promo-mechanics' );

        if ( empty( $ids ) ) {
            $this->redirect_with_notice( $message );
        }

        switch ( $action ) {
            case 'activate':
                $affected = $this->bulk_status( $ids, 'publish' );
                $message  = sprintf( __( 'Attivati %d prodotti.', 'advanced-promo-mechanics' ), $affected );
                break;
            case 'deactivate':
                $affected = $this->bulk_status( $ids, 'draft' );
                $message  = sprintf( __( 'Disattivati %d prodotti.', 'advanced-promo-mechanics' ), $affected );
                break;
            case 'change_category':
                $affected = $this->bulk_category( $ids, $category );
                $message  = sprintf( __( 'Aggiornata categoria per %d prodotti.', 'advanced-promo-mechanics' ), $affected );
                break;
            case 'export_csv':
                $file = $this->export_csv( $ids, $csv_cols );
                if ( $file ) {
                    wp_safe_redirect( $file );
                    exit;
                }
                $message = __( 'Errore durante la generazione del CSV.', 'advanced-promo-mechanics' );
                break;
            default:
                $message = __( 'Seleziona un’azione valida.', 'advanced-promo-mechanics' );
        }

        $this->redirect_with_notice( $message );
    }

    public function handle_full_catalog_export() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_export_full_catalog' );

        if ( ! $this->license->is_active() ) {
            $this->redirect_with_notice( __( 'Funzionalità disponibile solo con licenza attiva.', 'advanced-promo-mechanics' ) );
        }

        $ids = $this->get_all_product_ids();
        if ( empty( $ids ) ) {
            $this->redirect_with_notice( __( 'Nessun prodotto disponibile per l’export completo.', 'advanced-promo-mechanics' ) );
        }

        $file = $this->export_csv( $ids, [ 'ID', 'post_title', 'price', 'sku' ] );
        if ( $file ) {
            wp_safe_redirect( $file );
            exit;
        }

        $this->redirect_with_notice( __( 'Errore durante la generazione del CSV.', 'advanced-promo-mechanics' ) );
    }

    public function register_bulk_actions( array $actions ) : array {
        if ( ! $this->license->is_active() ) {
            return $actions;
        }
        $actions['aurora_select_all_command'] = __( '[Aurora] Seleziona tutto il catalogo', 'advanced-promo-mechanics' );
        $actions['aurora_activate']           = __( '[Aurora] Attiva prodotti', 'advanced-promo-mechanics' );
        $actions['aurora_deactivate']         = __( '[Aurora] Disattiva prodotti', 'advanced-promo-mechanics' );
        $actions['aurora_change_category']    = __( '[Aurora] Cambia categoria', 'advanced-promo-mechanics' );
        $actions['aurora_export_csv']         = __( '[Aurora] Esporta CSV', 'advanced-promo-mechanics' );
        return $actions;
    }

    public function handle_bulk_action( string $redirect_to, string $action, array $post_ids ) : string {
        if ( 'aurora_select_all_command' === $action ) {
            $notice = $this->create_selection_from_current_filters();
            return add_query_arg( 'apm_catalog_notice', rawurlencode( $notice ), $redirect_to );
        }

        if ( ! str_starts_with( $action, 'aurora_' ) ) {
            return $redirect_to;
        }

        if ( ! $this->license->is_active() ) {
            return add_query_arg( 'apm_catalog_notice', rawurlencode( __( 'Funzionalità disponibile solo con licenza attiva.', 'advanced-promo-mechanics' ) ), $redirect_to );
        }

        $category_id = isset( $_REQUEST['apm_bulk_category'] ) ? absint( $_REQUEST['apm_bulk_category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $csv_fields  = isset( $_REQUEST['apm_bulk_csv'] ) ? array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_REQUEST['apm_bulk_csv'] ) ) ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification
        $token       = isset( $_REQUEST['apm_bulk_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['apm_bulk_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $selection = $this->resolve_selection_for_action( $post_ids, $token );
        if ( is_wp_error( $selection ) ) {
            return add_query_arg( 'apm_catalog_notice', rawurlencode( $selection->get_error_message() ), $redirect_to );
        }

        $job_args = $this->build_job_arguments( $action, [
            'category_id' => $category_id,
            'csv_fields'  => $csv_fields,
        ] );

        if ( is_wp_error( $job_args ) ) {
            return add_query_arg( 'apm_catalog_notice', rawurlencode( $job_args->get_error_message() ), $redirect_to );
        }

        $job_id = $this->enqueue_bulk_job( $action, $selection['token'], $job_args, $selection['count'] );
        if ( is_wp_error( $job_id ) ) {
            $notice = $job_id->get_error_message();
        } else {
            $this->clear_user_selection_token();
            $notice = sprintf( __( 'Operazione avviata (%d prodotti). Ti aggiorniamo qui sotto.', 'advanced-promo-mechanics' ), $selection['count'] );
        }

        return add_query_arg( 'apm_catalog_notice', rawurlencode( $notice ), $redirect_to );
    }

    public function render_bulk_hidden_fields( $post_type, $which ) : void {
        if ( 'product' !== $post_type || 'top' !== $which ) {
            return;
        }
        echo '<input type="hidden" name="apm_bulk_category" value="" />';
        echo '<input type="hidden" name="apm_bulk_csv" value="" />';
        echo '<input type="hidden" name="apm_bulk_token" value="" />';
    }

    public function maybe_render_notice() : void {
        if ( empty( $_GET['apm_catalog_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && ! in_array( $screen->id, [ 'toplevel_page_aurora-project', 'aurora-project_page_aurora-catalog-actions', 'edit-product' ], true ) ) {
            // Do nothing, still show notice globally for admins.
        }
        $message = wp_kses_post( wp_unslash( $_GET['apm_catalog_notice'] ) );
        echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
    }

    public function render_job_progress_notices() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $job_ids = $this->get_user_job_ids();
        if ( empty( $job_ids ) ) {
            return;
        }
        foreach ( $job_ids as $job_id ) {
            $job = $this->get_job_state( $job_id );
            if ( ! $job ) {
                $this->remove_job_from_user( $job_id );
                continue;
            }

            $class = 'notice notice-info';
            if ( 'failed' === $job['status'] ) {
                $class = 'notice notice-error';
            } elseif ( 'completed' === $job['status'] ) {
                $class = 'notice notice-success';
            }

            $message = esc_html( $job['message'] ?? '' );
            if ( 'completed' === $job['status'] && 'aurora_export_csv' === $job['action'] && ! empty( $job['args']['csv_url'] ) ) {
                $url = esc_url( $job['args']['csv_url'] );
                $message .= ' <a href="' . $url . '" class="button button-secondary" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Scarica CSV', 'advanced-promo-mechanics' ) . '</a>';
            }

            echo '<div class="' . esc_attr( $class ) . '"><p>' . $message . '</p></div>';

            if ( in_array( $job['status'], [ 'completed', 'failed' ], true ) ) {
                $this->remove_job_from_user( $job_id );
                $this->delete_job_state( $job_id );
            }
        }
    }

    public function handle_select_all_ajax() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) ], 403 );
        }
        check_ajax_referer( 'apm_select_all', 'nonce' );

        $filters = $this->build_list_filters_from_request( $_POST );
        $ids     = $this->query_ids_by_list_filters( $filters );
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => __( 'Nessun prodotto trovato con i filtri correnti.', 'advanced-promo-mechanics' ) ], 400 );
        }

        $token = $this->create_selection_entry( $ids, $filters, true );
        wp_send_json_success( [
            'token' => $token,
            'count' => count( $ids ),
        ] );
    }

    public function process_bulk_job( array $args ) : void {
        $job_id = $args['job_id'] ?? '';
        if ( ! $job_id ) {
            return;
        }

        $job = $this->get_job_state( $job_id );
        if ( ! $job ) {
            return;
        }

        $selection = $this->get_selection( $job['token'] );
        if ( ! $selection ) {
            $this->fail_job( $job, __( 'La selezione globale è scaduta. Riesegui “[Aurora] Seleziona tutto il catalogo”.', 'advanced-promo-mechanics' ) );
            return;
        }

        $total  = (int) ( $selection['total'] ?? count( $selection['ids'] ) );
        $cursor = (int) ( $selection['cursor'] ?? 0 );

        if ( $cursor >= $total ) {
            $this->finish_job_success( $job, $selection );
            return;
        }

        $batch = array_slice( $selection['ids'], $cursor, $this->batch_size );
        if ( empty( $batch ) ) {
            $this->finish_job_success( $job, $selection );
            return;
        }

        try {
            $this->run_action_chunk( $job, $batch );
        } catch ( Throwable $exception ) {
            $this->fail_job( $job, $exception->getMessage() );
            return;
        }

        $selection['cursor'] = $cursor + count( $batch );
        $job['processed']    = min( $job['processed'] + count( $batch ), $total );
        $job['status']       = ( $selection['cursor'] >= $total ) ? 'completed' : 'running';
        $job['updated_at']   = time();
        $job['message']      = sprintf( __( 'Elaborazione in corso: %1$d/%2$d prodotti.', 'advanced-promo-mechanics' ), $job['processed'], $total );

        $this->save_selection( $job['token'], $selection );

        if ( 'completed' === $job['status'] ) {
            $this->finish_job_success( $job, $selection );
            return;
        }

        $this->save_job_state( $job );
        as_enqueue_async_action( 'apm_run_bulk_job', [ 'job_id' => $job_id ], 'apm' );
    }

    public function set_products_per_page( $per_page ) {
        return 100;
    }

    public function get_user_selection_token() : string {
        $token = get_user_meta( get_current_user_id(), 'apm_selection_token', true );
        return is_string( $token ) ? $token : '';
    }

    private function parse_filters() : array {
        $filters = [];
        if ( ! empty( $_POST['apm_filter_status'] ) ) {
            $filters['post_status'] = sanitize_key( $_POST['apm_filter_status'] );
        }
        if ( ! empty( $_POST['apm_filter_category'] ) ) {
            $filters['category'] = array_map( 'absint', (array) $_POST['apm_filter_category'] );
        }
        return $filters;
    }

    private function get_product_ids( array $filters ) : array {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ( isset( $filters['post_status'] ) ) {
            $args['post_status'] = $filters['post_status'];
        }
        if ( ! empty( $filters['category'] ) ) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $filters['category'],
            ];
        }
        $query = new WP_Query( $args );
        return $query->posts;
    }

    private function get_all_product_ids() : array {
        $query = new WP_Query( [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ] );
        return array_map( 'intval', $query->posts );
    }

    private function resolve_selection_for_action( array $post_ids, string $token ) {
        $current_user = get_current_user_id();
        if ( $token ) {
            $selection = $this->get_selection( $token );
            if ( ! $selection ) {
                return new WP_Error( 'apm_selection_missing', __( 'La selezione globale non è più disponibile. Riesegui “[Aurora] Seleziona tutto il catalogo”.', 'advanced-promo-mechanics' ) );
            }
            if ( (int) $selection['created_by'] !== $current_user ) {
                return new WP_Error( 'apm_selection_forbidden', __( 'Non puoi usare una selezione creata da un altro utente.', 'advanced-promo-mechanics' ) );
            }
            return [
                'token' => $token,
                'count' => (int) ( $selection['total'] ?? count( $selection['ids'] ) ),
            ];
        }

        if ( empty( $post_ids ) ) {
            return new WP_Error( 'apm_selection_empty', __( 'Seleziona almeno un prodotto o crea una selezione globale.', 'advanced-promo-mechanics' ) );
        }

        $new_token = $this->create_selection_entry( $post_ids, [ 'source' => 'page' ], false );
        return [
            'token' => $new_token,
            'count' => count( $post_ids ),
        ];
    }

    private function build_job_arguments( string $action, array $context ) {
        switch ( $action ) {
            case 'aurora_change_category':
                if ( empty( $context['category_id'] ) ) {
                    return new WP_Error( 'apm_missing_category', __( 'Seleziona una categoria di destinazione.', 'advanced-promo-mechanics' ) );
                }
                return [ 'category_id' => (int) $context['category_id'] ];
            case 'aurora_export_csv':
                $fields     = ! empty( $context['csv_fields'] ) ? $context['csv_fields'] : [ 'ID', 'post_title', 'price' ];
                $upload_dir = wp_upload_dir();
                if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
                    return new WP_Error( 'apm_upload_dir_error', __( 'Impossibile determinare la cartella di upload.', 'advanced-promo-mechanics' ) );
                }
                $dir = trailingslashit( $upload_dir['basedir'] ) . 'apm-exports/';
                wp_mkdir_p( $dir );
                $filename = 'apm-catalog-' . time() . '-' . wp_generate_password( 4, false, false ) . '.csv';
                return [
                    'csv_fields'          => $fields,
                    'csv_path'            => $dir . $filename,
                    'csv_url'             => trailingslashit( $upload_dir['baseurl'] ) . 'apm-exports/' . $filename,
                    'csv_header_written'  => false,
                ];
            default:
                return [];
        }
    }

    private function enqueue_bulk_job( string $action, string $token, array $job_args, int $total ) {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return new WP_Error( 'apm_scheduler_missing', __( 'Action Scheduler non è disponibile. Assicurati che WooCommerce sia attivo.', 'advanced-promo-mechanics' ) );
        }

        $selection = $this->get_selection( $token );
        if ( ! $selection ) {
            return new WP_Error( 'apm_selection_missing', __( 'La selezione globale non è più disponibile.', 'advanced-promo-mechanics' ) );
        }

        $job_id = sanitize_key( wp_generate_uuid4() );
        $job    = [
            'id'         => $job_id,
            'action'     => $action,
            'token'      => $token,
            'args'       => $job_args,
            'total'      => $total,
            'processed'  => 0,
            'status'     => 'queued',
            'message'    => __( 'In coda…', 'advanced-promo-mechanics' ),
            'created_by' => get_current_user_id(),
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->save_job_state( $job );
        $this->add_job_to_user( $job_id );
        as_enqueue_async_action( 'apm_run_bulk_job', [ 'job_id' => $job_id ], 'apm' );

        return $job_id;
    }

    private function create_selection_from_current_filters() : string {
        $filters = $this->build_list_filters_from_request( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
        $ids     = $this->query_ids_by_list_filters( $filters );
        if ( empty( $ids ) ) {
            return __( 'Nessun prodotto trovato con i filtri correnti.', 'advanced-promo-mechanics' );
        }
        $token = $this->create_selection_entry( $ids, $filters, true );
        return sprintf( __( 'Selezione globale pronta: %d prodotti. Ora scegli un’azione Aurora.', 'advanced-promo-mechanics' ), count( $ids ) );
    }

    private function create_selection_entry( array $ids, array $filters = [], bool $share_with_user = false ) : string {
        $clean_ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
        $token     = sanitize_key( wp_generate_uuid4() );
        $selection = [
            'ids'        => $clean_ids,
            'filters'    => $filters,
            'cursor'     => 0,
            'total'      => count( $clean_ids ),
            'created_by' => get_current_user_id(),
            'created_at' => time(),
        ];
        $this->save_selection( $token, $selection );
        if ( $share_with_user ) {
            $this->store_user_selection_token( $token );
        }
        return $token;
    }

    private function build_list_filters_from_request( array $source ) : array {
        $filters = [];
        if ( isset( $source['s'] ) && '' !== $source['s'] ) {
            $filters['search'] = sanitize_text_field( wp_unslash( $source['s'] ) );
        }
        if ( isset( $source['post_status'] ) && '' !== $source['post_status'] && 'all' !== $source['post_status'] ) {
            $filters['status'] = sanitize_key( $source['post_status'] );
        }
        if ( isset( $source['product_cat'] ) && '' !== $source['product_cat'] ) {
            $filters['product_cat'] = sanitize_text_field( wp_unslash( $source['product_cat'] ) );
        }
        if ( isset( $source['product_type'] ) && '' !== $source['product_type'] ) {
            $filters['product_type'] = sanitize_text_field( wp_unslash( $source['product_type'] ) );
        }
        if ( isset( $source['_stock_status'] ) && '' !== $source['_stock_status'] ) {
            $filters['stock_status'] = sanitize_key( $source['_stock_status'] );
        }
        if ( isset( $source['product_brand'] ) && '' !== $source['product_brand'] ) {
            $filters['product_brand'] = sanitize_text_field( wp_unslash( $source['product_brand'] ) );
        }
        return $filters;
    }

    private function query_ids_by_list_filters( array $filters ) : array {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ];

        if ( ! empty( $filters['status'] ) ) {
            $args['post_status'] = $filters['status'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $args['s'] = $filters['search'];
        }

        $tax_query = [];
        if ( ! empty( $filters['product_cat'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $filters['product_cat'],
            ];
        }
        if ( ! empty( $filters['product_type'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => $filters['product_type'],
            ];
        }
        if ( ! empty( $filters['product_brand'] ) ) {
            $tax_query[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => $filters['product_brand'],
            ];
        }
        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query;
        }

        if ( ! empty( $filters['stock_status'] ) ) {
            $args['meta_query'][] = [
                'key'   => '_stock_status',
                'value' => $filters['stock_status'],
            ];
        }

        $query = new WP_Query( $args );
        return $query->posts;
    }

    private function save_selection( string $token, array $selection ) : void {
        set_transient( $this->get_selection_key( $token ), $selection, self::SELECTION_TTL );
    }

    private function get_selection( string $token ) : ?array {
        $data = get_transient( $this->get_selection_key( $token ) );
        return is_array( $data ) ? $data : null;
    }

    private function delete_selection( string $token ) : void {
        delete_transient( $this->get_selection_key( $token ) );
    }

    private function get_selection_key( string $token ) : string {
        return 'apm_selection_' . sanitize_key( $token );
    }

    private function save_job_state( array $job ) : void {
        set_transient( $this->get_job_key( $job['id'] ), $job, self::JOB_TTL );
    }

    private function get_job_state( string $job_id ) : ?array {
        $state = get_transient( $this->get_job_key( $job_id ) );
        return is_array( $state ) ? $state : null;
    }

    private function delete_job_state( string $job_id ) : void {
        delete_transient( $this->get_job_key( $job_id ) );
    }

    private function get_job_key( string $job_id ) : string {
        return 'apm_bulk_job_' . sanitize_key( $job_id );
    }

    private function add_job_to_user( string $job_id ) : void {
        $user_id = get_current_user_id();
        $jobs    = $this->get_user_job_ids();
        if ( ! in_array( $job_id, $jobs, true ) ) {
            $jobs[] = $job_id;
            update_user_meta( $user_id, 'apm_active_jobs', array_values( $jobs ) );
        }
    }

    private function remove_job_from_user( string $job_id ) : void {
        $user_id = get_current_user_id();
        $jobs    = $this->get_user_job_ids();
        $jobs    = array_values( array_filter( $jobs, static fn( $id ) => $id !== $job_id ) );
        if ( empty( $jobs ) ) {
            delete_user_meta( $user_id, 'apm_active_jobs' );
        } else {
            update_user_meta( $user_id, 'apm_active_jobs', $jobs );
        }
    }

    private function get_user_job_ids() : array {
        $jobs = get_user_meta( get_current_user_id(), 'apm_active_jobs', true );
        return is_array( $jobs ) ? $jobs : [];
    }

    private function store_user_selection_token( string $token ) : void {
        update_user_meta( get_current_user_id(), 'apm_selection_token', $token );
    }

    private function clear_user_selection_token() : void {
        delete_user_meta( get_current_user_id(), 'apm_selection_token' );
    }

    private function run_action_chunk( array &$job, array $ids ) : void {
        switch ( $job['action'] ) {
            case 'aurora_activate':
                $this->bulk_status( $ids, 'publish' );
                break;
            case 'aurora_deactivate':
                $this->bulk_status( $ids, 'draft' );
                break;
            case 'aurora_change_category':
                $category = (int) ( $job['args']['category_id'] ?? 0 );
                if ( ! $category ) {
                    throw new \RuntimeException( __( 'Categoria di destinazione non valida.', 'advanced-promo-mechanics' ) );
                }
                $this->bulk_category( $ids, $category );
                break;
            case 'aurora_export_csv':
                $this->append_csv_rows( $ids, $job );
                break;
            default:
                throw new \RuntimeException( __( 'Azione non riconosciuta.', 'advanced-promo-mechanics' ) );
        }
    }

    private function append_csv_rows( array $ids, array &$job ) : void {
        $fields = $job['args']['csv_fields'] ?? [ 'ID', 'post_title', 'price' ];
        $path   = $job['args']['csv_path'] ?? '';
        if ( ! $path ) {
            throw new \RuntimeException( __( 'Percorso CSV non valido.', 'advanced-promo-mechanics' ) );
        }
        $header_written = ! empty( $job['args']['csv_header_written'] );
        $handle         = fopen( $path, $header_written ? 'a' : 'w' );
        if ( ! $handle ) {
            throw new \RuntimeException( __( 'Impossibile scrivere il file CSV.', 'advanced-promo-mechanics' ) );
        }
        if ( ! $header_written ) {
            fputcsv( $handle, $fields );
            $job['args']['csv_header_written'] = true;
        }
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }
            fputcsv( $handle, $this->map_product_row( $product, $fields ) );
        }
        fclose( $handle );
    }

    private function map_product_row( WC_Product $product, array $columns ) : array {
        $row = [];
        $id  = $product->get_id();
        foreach ( $columns as $col ) {
            switch ( $col ) {
                case 'ID':
                    $row[] = $id;
                    break;
                case 'post_title':
                    $row[] = get_the_title( $id );
                    break;
                case 'price':
                    $row[] = $product->get_price();
                    break;
                case 'sku':
                    $row[] = $product->get_sku();
                    break;
                default:
                    $row[] = get_post_meta( $id, $col, true );
            }
        }
        return $row;
    }

    private function finish_job_success( array $job, array $selection ) : void {
        $job['status']       = 'completed';
        $job['processed']    = $job['total'];
        $job['updated_at']   = time();
        $job['message']      = __( 'Operazione completata.', 'advanced-promo-mechanics' );
        if ( 'aurora_export_csv' === $job['action'] && ! empty( $job['args']['csv_url'] ) ) {
            $job['message'] = __( 'CSV pronto. Puoi scaricarlo dal pulsante qui sotto.', 'advanced-promo-mechanics' );
        }
        $this->save_job_state( $job );
        $this->delete_selection( $job['token'] );
    }

    private function fail_job( array $job, string $message ) : void {
        $job['status']     = 'failed';
        $job['message']    = sprintf( __( 'Operazione fallita: %s', 'advanced-promo-mechanics' ), $message );
        $job['updated_at'] = time();
        $this->save_job_state( $job );
        $this->delete_selection( $job['token'] );
    }

    private function bulk_status( array $ids, string $status ) : int {
        $count = 0;
        foreach ( $ids as $id ) {
            $updated = wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
            if ( $updated ) {
                $count++;
            }
        }
        return $count;
    }

    private function bulk_price( array $ids, float $percent ) : int {
        $count = 0;
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }
            $price = (float) $product->get_regular_price();
            $new   = $price + ( $price * ( $percent / 100 ) );
            $product->set_regular_price( wc_format_decimal( $new, wc_get_price_decimals() ) );
            $product->save();
            $count++;
        }
        return $count;
    }

    private function bulk_category( array $ids, int $category_id ) : int {
        if ( ! $category_id ) {
            return 0;
        }
        $count = 0;
        foreach ( $ids as $id ) {
            $result = wp_set_object_terms( $id, [ $category_id ], 'product_cat', false );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }
        return $count;
    }

    private function export_csv( array $ids, array $columns ) {
        if ( empty( $columns ) ) {
            $columns = [ 'ID', 'post_title', 'price' ];
        }
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'apm-exports/';
        wp_mkdir_p( $dir );
        $filename = 'apm-catalog-' . time() . '.csv';
        $filepath = $dir . $filename;
        $handle   = fopen( $filepath, 'w' );
        if ( ! $handle ) {
            return false;
        }
        fputcsv( $handle, $columns );
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }
            fputcsv( $handle, $this->map_product_row( $product, $columns ) );
        }
        fclose( $handle );
        return trailingslashit( $upload_dir['baseurl'] ) . 'apm-exports/' . $filename;
    }

    private function redirect_with_notice( string $message ) : void {
        wp_safe_redirect( add_query_arg( [
            'page'               => 'aurora-catalog-actions',
            'apm_catalog_notice' => rawurlencode( $message ),
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
