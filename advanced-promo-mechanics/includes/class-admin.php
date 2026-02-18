<?php
namespace APM;

class Admin {
    private Rules_Store $rules_store;
    private Logger $logger;
    private License $license;
    private Catalog_Actions $catalog_actions;
    private Marketplace_Credentials $marketplace_credentials;
    private Sku_Map $sku_map;
    private Activity_Log $activity_log;

    public function __construct( Rules_Store $rules_store, Logger $logger, License $license, Catalog_Actions $catalog_actions, Marketplace_Credentials $marketplace_credentials, Sku_Map $sku_map, Activity_Log $activity_log ) {
        $this->rules_store              = $rules_store;
        $this->logger                   = $logger;
        $this->license                  = $license;
        $this->catalog_actions          = $catalog_actions;
        $this->marketplace_credentials  = $marketplace_credentials;
        $this->sku_map                  = $sku_map;
        $this->activity_log             = $activity_log;
    }

    public function init() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        add_action( 'save_post_apm_rule', [ $this, 'save_rule_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_apm_save_license', [ $this, 'handle_license_form' ] );
        add_action( 'admin_post_apm_save_repricer_settings', [ $this, 'handle_repricer_settings_form' ] );
        add_action( 'admin_post_apm_save_marketplace_account', [ $this, 'handle_marketplace_account_form' ] );
        add_action( 'admin_post_apm_delete_marketplace_account', [ $this, 'handle_marketplace_account_delete' ] );
        add_action( 'admin_post_apm_save_sku_map', [ $this, 'handle_sku_map_save' ] );
        add_action( 'admin_post_apm_delete_sku_map', [ $this, 'handle_sku_map_delete' ] );
    }

    public function register_menu() : void {
        $icon = APM_PLUGIN_URL . 'assets/admin/img/aurora-icon.svg';

        add_menu_page(
            __( 'Aurora Project', 'advanced-promo-mechanics' ),
            __( 'Aurora Project', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-project',
            [ $this, 'render_page' ],
            $icon,
            56
        );

        add_submenu_page(
            'aurora-project',
            __( 'Promozioni Avanzate', 'advanced-promo-mechanics' ),
            __( 'Promozioni Avanzate', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-project',
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            'aurora-project',
            __( 'Azioni catalogo', 'advanced-promo-mechanics' ),
            __( 'Azioni catalogo', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-catalog-actions',
            [ $this->catalog_actions, 'render_page' ]
        );

        add_submenu_page(
            'aurora-project',
            __( 'Repricer', 'advanced-promo-mechanics' ),
            __( 'Repricer', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-repricer',
            [ $this, 'render_repricer_page' ]
        );

        add_submenu_page(
            'aurora-project',
            __( 'Licenza', 'advanced-promo-mechanics' ),
            __( 'Licenza', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-license',
            [ $this, 'render_license_page' ]
        );

        add_submenu_page(
            'aurora-project',
            __( 'Log attività', 'advanced-promo-mechanics' ),
            __( 'Log attività', 'advanced-promo-mechanics' ),
            'manage_woocommerce',
            'aurora-activity-log',
            [ $this, 'render_activity_log_page' ]
        );
    }

    public function render_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $rules = $this->rules_store->get_rules();
        $settings = get_option( 'apm_settings', [] );

        include APM_PLUGIN_DIR . 'includes/views/admin-page.php';
    }

    public function render_license_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $status_message = $this->license->get_status_message();
        $days_left      = $this->license->days_left();
        $license_key    = $this->license->get_license_key();
        include APM_PLUGIN_DIR . 'includes/views/license-page.php';
    }

    public function render_activity_log_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $entries = $this->activity_log->latest( 200 );
        include APM_PLUGIN_DIR . 'includes/views/activity-log-page.php';
    }

    public function render_repricer_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $settings = apm_get_repricer_settings();
        $notice   = isset( $_GET['apm_notice'] ) ? wp_kses_post( wp_unslash( $_GET['apm_notice'] ) ) : ''; // phpcs:ignore
        $accounts = $this->marketplace_credentials->all();
        $sku_links = $this->sku_map->all();
        include APM_PLUGIN_DIR . 'includes/views/repricer-settings-page.php';
    }

    public function register_meta_box() : void {
        add_meta_box(
            'apm_rule_meta',
            __( 'Dettagli regola', 'advanced-promo-mechanics' ),
            [ $this, 'render_meta_box' ],
            'apm_rule',
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) : void {
        wp_nonce_field( 'apm_rule_nonce', 'apm_rule_nonce' );
        $data = $this->rules_store->get_rule_meta( $post->ID );
        include APM_PLUGIN_DIR . 'includes/views/meta-box.php';
    }

    public function save_rule_meta( int $post_id, $post ) : void {
        if ( ! isset( $_POST['apm_rule_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apm_rule_nonce'] ) ), 'apm_rule_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $payload = $_POST['apm_rule'] ?? [];
        $this->rules_store->save_rule_meta( $post_id, $payload );
        $this->logger->debug( 'Saved rule meta', [ 'rule_id' => $post_id ] );
        apm_log_activity( 'rule_saved', __( 'Regola promozione aggiornata.', 'advanced-promo-mechanics' ), [ 'rule_id' => $post_id ] );
    }

    public function enqueue_assets( string $hook ) : void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_rule_editor   = $screen && 'apm_rule' === $screen->post_type;
        $is_aurora_page   = in_array( $hook, [ 'toplevel_page_aurora-project', 'aurora-project_page_aurora-project', 'aurora-project_page_aurora-license', 'aurora-project_page_aurora-catalog-actions', 'aurora-project_page_aurora-repricer' ], true );
        $is_product_table = $screen && 'edit-product' === $screen->id && $this->license->is_active();

        if ( ! $is_rule_editor && ! $is_aurora_page && ! $is_product_table ) {
            return;
        }

        wp_enqueue_style( 'apm-admin', APM_PLUGIN_URL . 'assets/admin/css/admin.css', [], APM_VERSION );
        wp_enqueue_script( 'apm-admin', APM_PLUGIN_URL . 'assets/admin/js/admin.js', [], APM_VERSION, true );

        $script_data = [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'apm_select_all' ),
            'selectionToken' => $this->catalog_actions->get_user_selection_token(),
            'i18n'           => [
                'selectAllReady'  => __( 'Selezione globale pronta: %d prodotti. Ora scegli un’azione Aurora.', 'advanced-promo-mechanics' ),
                'selectAllError'  => __( 'Impossibile completare la selezione globale. Riprova.', 'advanced-promo-mechanics' ),
                'selectAllStart'  => __( 'Sto preparando la selezione globale…', 'advanced-promo-mechanics' ),
                'categoryPrompt'  => __( 'Inserisci l\'ID della categoria di destinazione', 'advanced-promo-mechanics' ),
                'csvPrompt'       => __( 'Campi CSV separati da virgola (es. ID,post_title,price,sku)', 'advanced-promo-mechanics' ),
            ],
        ];
        wp_localize_script( 'apm-admin', 'apmAdmin', $script_data );
    }

    public function handle_license_form() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_license' );
        $license_key = isset( $_POST['apm_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['apm_license_key'] ) ) : '';

        if ( $license_key ) {
            $this->license->store_license( $license_key );
            $message = __( 'License key salvata correttamente.', 'advanced-promo-mechanics' );
            apm_log_activity( 'license_saved', __( 'License key aggiornata.', 'advanced-promo-mechanics' ), [] );
        } else {
            $this->license->remove_license();
            $message = __( 'License key rimossa. Il plugin tornerà in trial (se ancora disponibile).', 'advanced-promo-mechanics' );
            apm_log_activity( 'license_removed', __( 'License key rimossa.', 'advanced-promo-mechanics' ), [] );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-license', 'apm_notice' => rawurlencode( $message ) ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_repricer_settings_form() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_repricer_settings' );

        $settings = [
            'default_min_margin'    => $this->sanitize_float( $_POST['default_min_margin'] ?? 20, 0, 100 ),
            'stock_push_threshold'  => max( 0, (int) ( $_POST['stock_push_threshold'] ?? 100 ) ),
            'stock_push_discount'   => $this->sanitize_float( $_POST['stock_push_discount'] ?? 10, 0, 90 ),
            'stock_push_min_margin' => $this->sanitize_float( $_POST['stock_push_min_margin'] ?? 5, 0, 100 ),
            'batch_size'            => max( 1, (int) ( $_POST['batch_size'] ?? 50 ) ),
            'schedule'              => $this->sanitize_schedule( $_POST['schedule'] ?? '5min' ),
        ];

        update_option( 'apm_repricer_settings', $settings );
        update_option( 'apm_default_min_margin', $settings['default_min_margin'] );
        update_option( 'apm_stock_push_threshold', $settings['stock_push_threshold'] );
        update_option( 'apm_stock_push_discount', $settings['stock_push_discount'] );
        update_option( 'apm_stock_push_min_margin', $settings['stock_push_min_margin'] );

        do_action( 'apm_repricer_settings_updated', $settings );

        $message = __( 'Impostazioni repricer salvate.', 'advanced-promo-mechanics' );
        apm_log_activity( 'repricer_settings_saved', __( 'Impostazioni repricer aggiornate.', 'advanced-promo-mechanics' ), $settings );
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer', 'apm_notice' => rawurlencode( $message ) ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_marketplace_account_form() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_marketplace_account' );

        $marketplace = sanitize_key( $_POST['marketplace'] ?? '' );
        $label       = sanitize_text_field( wp_unslash( $_POST['account_label'] ?? '' ) );

        if ( ! in_array( $marketplace, [ 'amazon', 'ebay' ], true ) || empty( $label ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer', 'apm_notice' => rawurlencode( __( 'Parametri non validi.', 'advanced-promo-mechanics' ) ) ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $data = [];
        if ( 'amazon' === $marketplace ) {
            $data = [
                'seller_id'    => sanitize_text_field( wp_unslash( $_POST['amazon_seller_id'] ?? '' ) ),
                'client_id'    => sanitize_text_field( wp_unslash( $_POST['amazon_client_id'] ?? '' ) ),
                'client_secret'=> sanitize_text_field( wp_unslash( $_POST['amazon_client_secret'] ?? '' ) ),
                'refresh_token'=> sanitize_text_field( wp_unslash( $_POST['amazon_refresh_token'] ?? '' ) ),
                'role_arn'     => sanitize_text_field( wp_unslash( $_POST['amazon_role_arn'] ?? '' ) ),
                'marketplace'  => sanitize_text_field( wp_unslash( $_POST['amazon_marketplace'] ?? '' ) ),
            ];
        } else {
            $data = [
                'ru_name'        => sanitize_text_field( wp_unslash( $_POST['ebay_ru_name'] ?? '' ) ),
                'client_id'      => sanitize_text_field( wp_unslash( $_POST['ebay_client_id'] ?? '' ) ),
                'client_secret'  => sanitize_text_field( wp_unslash( $_POST['ebay_client_secret'] ?? '' ) ),
                'refresh_token'  => sanitize_text_field( wp_unslash( $_POST['ebay_refresh_token'] ?? '' ) ),
                'marketplace_id' => sanitize_text_field( wp_unslash( $_POST['ebay_marketplace_id'] ?? '' ) ),
            ];
        }

        $saved_id = $this->marketplace_credentials->upsert( $marketplace, $label, $data );
        if ( $saved_id ) {
            do_action( 'apm_marketplace_account_saved', (int) $saved_id, $marketplace );
            apm_log_activity( 'marketplace_account_saved', __( 'Account marketplace salvato.', 'advanced-promo-mechanics' ), [ 'account_id' => (int) $saved_id, 'marketplace' => $marketplace, 'label' => $label ] );
        }
        $message = $saved_id ? __( 'Account salvato.', 'advanced-promo-mechanics' ) : __( 'Errore nel salvataggio.', 'advanced-promo-mechanics' );

        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer', 'apm_notice' => rawurlencode( $message ) ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_marketplace_account_delete() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_delete_marketplace_account' );
        $id = absint( $_POST['account_id'] ?? 0 );
        if ( $id ) {
            $account = $this->marketplace_credentials->get( $id );
            $deleted = $this->marketplace_credentials->delete( $id );
            if ( $deleted && $account ) {
                do_action( 'apm_marketplace_account_deleted', $id, $account['marketplace'] );
                apm_log_activity( 'marketplace_account_deleted', __( 'Account marketplace eliminato.', 'advanced-promo-mechanics' ), [ 'account_id' => $id, 'marketplace' => $account['marketplace'] ?? '' ] );
            }
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_sku_map_save() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_save_sku_map' );
        $product_id   = absint( $_POST['sku_product_id'] ?? 0 );
        $variation_id = absint( $_POST['sku_variation_id'] ?? 0 );
        $marketplace  = sanitize_key( $_POST['sku_marketplace'] ?? '' );
        $marketplace_sku = sanitize_text_field( wp_unslash( $_POST['sku_marketplace_sku'] ?? '' ) );
        $listing_id   = sanitize_text_field( wp_unslash( $_POST['sku_listing_id'] ?? '' ) );

        if ( ! $product_id || empty( $marketplace ) || empty( $marketplace_sku ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer', 'apm_notice' => rawurlencode( __( 'Parametri mapping non validi.', 'advanced-promo-mechanics' ) ) ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $this->sku_map->upsert( $product_id, $variation_id, $marketplace, $marketplace_sku, $listing_id );
        apm_log_activity( 'sku_map_saved', __( 'Link SKU marketplace salvato.', 'advanced-promo-mechanics' ), [ 'product_id' => $product_id, 'marketplace' => $marketplace ] );
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer', 'apm_notice' => rawurlencode( __( 'Mapping salvato.', 'advanced-promo-mechanics' ) ) ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_sku_map_delete() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permessi insufficienti.', 'advanced-promo-mechanics' ) );
        }
        check_admin_referer( 'apm_delete_sku_map' );
        $id = absint( $_POST['sku_map_id'] ?? 0 );
        if ( $id ) {
            $this->sku_map->delete( $id );
            apm_log_activity( 'sku_map_deleted', __( 'Link SKU marketplace eliminato.', 'advanced-promo-mechanics' ), [ 'id' => $id ] );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'aurora-repricer' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function sanitize_float( $value, float $min, float $max ) : float {
        $value = is_array( $value ) ? reset( $value ) : $value;
        $value = floatval( $value );
        return max( $min, min( $max, $value ) );
    }

    private function sanitize_schedule( $value ) : string {
        $value = sanitize_key( is_array( $value ) ? reset( $value ) : $value );
        return in_array( $value, [ '5min', '15min' ], true ) ? $value : '5min';
    }
}
