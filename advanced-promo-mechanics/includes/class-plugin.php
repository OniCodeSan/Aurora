<?php
namespace APM;

use APM\Admin;
use APM\Rules_Store;
use APM\Rules_Engine;
use APM\Logger;
use APM\CLI;
use APM\Integrations\Amazon_Connector;
use APM\Integrations\Ebay_Connector;

class Plugin {
    private Admin $admin;
    private Rules_Store $rules_store;
    private Rules_Engine $engine;
    private Logger $logger;
    private License $license;
    private Catalog_Actions $catalog_actions;
    private Pricing_Meta $pricing_meta;
    private Repricer_Service $repricer;
    private Marketplace_Credentials $marketplace_credentials;
    private Marketplace_Scheduler $scheduler;
    private Amazon_Connector $amazon_connector;
    private Ebay_Connector $ebay_connector;
    private Sku_Map $sku_map;
    private Activity_Log $activity_log;
    private Feed_Logs $feed_logs;
    private Feed_Exporter $feed_exporter;
    private Feed_Module $feed_module;
    private Feed_Scheduler $feed_scheduler;

    public function init() : void {
        $this->license         = new License();
        $this->license->ensure_install_date();
        $this->logger          = new Logger();
        $this->rules_store     = new Rules_Store( $this->logger );
        $this->engine          = new Rules_Engine( $this->rules_store, $this->logger );
        $this->catalog_actions         = new Catalog_Actions( $this->license );
        $this->pricing_meta            = new Pricing_Meta();
        $this->repricer                = new Repricer_Service( $this->logger );
        $this->marketplace_credentials = new Marketplace_Credentials();
        $this->scheduler               = new Marketplace_Scheduler( $this->marketplace_credentials, $this->logger );
        $this->sku_map                 = new Sku_Map( $this->marketplace_credentials );
        $this->activity_log            = new Activity_Log();
        $this->admin                   = new Admin( $this->rules_store, $this->logger, $this->license, $this->catalog_actions, $this->marketplace_credentials, $this->sku_map, $this->activity_log );
        $this->amazon_connector        = new Amazon_Connector( $this->marketplace_credentials, $this->logger );
        $this->ebay_connector          = new Ebay_Connector( $this->marketplace_credentials, $this->logger );
        $feed_profiles                 = new Feed_Profiles();
        $this->feed_logs               = new Feed_Logs();
        $this->feed_exporter           = new Feed_Exporter( $this->feed_logs );
        $this->feed_module             = new Feed_Module( $feed_profiles, $this->feed_logs, $this->feed_exporter );
        $this->feed_scheduler          = new Feed_Scheduler( $feed_profiles, $this->feed_exporter, $this->feed_logs );

        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'init', [ $this, 'register_settings' ] );
        add_action( 'init', [ $this, 'maybe_flush_cache' ] );

        $this->admin->init();
        $this->catalog_actions->init();
        $this->pricing_meta->init();
        $this->repricer->init();
        $this->scheduler->init();
        $this->amazon_connector->init();
        $this->ebay_connector->init();
        $this->feed_module->init();
        $this->feed_scheduler->init();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'apm marketplace', new CLI( $this->scheduler, $this->marketplace_credentials ) );
        }

        if ( $this->license->is_active() ) {
            add_action( 'woocommerce_before_calculate_totals', [ $this->engine, 'apply_cart_adjustments' ], 20 );
            add_action( 'woocommerce_cart_calculate_fees', [ $this->engine, 'apply_cart_fees' ], 20, 1 );
            add_action( 'woocommerce_cart_updated', [ $this->engine, 'invalidate_cart_cache' ] );
            add_action( 'woocommerce_checkout_order_processed', [ $this->engine, 'invalidate_cart_cache' ] );
        } else {
            add_action( 'admin_notices', [ $this, 'render_license_notice' ] );
        }
    }

    public function register_post_type() : void {
        $labels = [
            'name'               => __( 'Regole Promozioni', 'advanced-promo-mechanics' ),
            'singular_name'      => __( 'Regola Promozione', 'advanced-promo-mechanics' ),
            'add_new'            => __( 'Aggiungi nuova regola', 'advanced-promo-mechanics' ),
            'add_new_item'       => __( 'Aggiungi regola', 'advanced-promo-mechanics' ),
            'edit_item'          => __( 'Modifica regola', 'advanced-promo-mechanics' ),
            'new_item'           => __( 'Nuova regola', 'advanced-promo-mechanics' ),
            'view_item'          => __( 'Vedi regola', 'advanced-promo-mechanics' ),
            'search_items'       => __( 'Cerca regole', 'advanced-promo-mechanics' ),
            'not_found'          => __( 'Nessuna regola trovata', 'advanced-promo-mechanics' ),
            'not_found_in_trash' => __( 'Nessuna regola nel cestino', 'advanced-promo-mechanics' ),
        ];

        register_post_type( 'apm_rule', [
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false,
            'capability_type' => 'shop_coupon',
            'map_meta_cap'    => true,
            'supports'        => [ 'title' ],
        ] );
    }

    public function register_settings() : void {
        register_setting( 'apm_settings', 'apm_settings', [ $this, 'sanitize_settings' ] );
    }

    public function sanitize_settings( array $input ) : array {
        $output = [
            'debug'         => ! empty( $input['debug'] ) ? 1 : 0,
            'force_gifts'   => ! empty( $input['force_gifts'] ) ? 1 : 0,
            'dry_run'       => ! empty( $input['dry_run'] ) ? 1 : 0,
        ];

        return $output;
    }

    public function maybe_flush_cache() : void {
        if ( isset( $_GET['apm_flush_rules'] ) && current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $this->rules_store->flush_cache();
        }
    }

    public function render_license_notice() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Advanced Promo Mechanics: trial scaduta. Inserisci una license key nella pagina Aurora Project → Licenza per riattivare le promozioni.', 'advanced-promo-mechanics' ) . '</p></div>';
    }
}
