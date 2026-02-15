<?php
namespace APM;

use WC_Product;

class Repricer_Service {
    private Logger $logger;
    private array $strategies = [];
    private string $tenant_id;
    private array $settings;

    public function __construct( Logger $logger ) {
        $this->logger    = $logger;
        $this->tenant_id = apply_filters( 'apm_current_tenant', 'default' );
        $this->settings  = apm_get_repricer_settings();
    }

    public function init() : void {
        $this->register_strategy( new Margin_Guard_Strategy() );
        $this->register_strategy( new Stock_Push_Strategy() );

        add_action( 'save_post_product', [ $this, 'handle_product_save' ], 20, 3 );
        add_action( 'save_post_product_variation', [ $this, 'handle_variation_save' ], 20, 3 );
        add_action( 'before_delete_post', [ $this, 'handle_post_delete' ] );

        add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
        add_filter( 'apm_repricing_batch', [ $this, 'filter_batch_size' ] );
        add_action( 'apm_repricer_settings_updated', [ $this, 'handle_settings_updated' ] );

        $this->maybe_schedule_event();
        add_action( 'apm_run_repricing', [ $this, 'run_repricing' ] );
    }

    public function register_schedule( array $schedules ) : array {
        if ( ! isset( $schedules['apm_five_minutes'] ) ) {
            $schedules['apm_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Ogni 5 minuti', 'advanced-promo-mechanics' ),
            ];
        }
        if ( ! isset( $schedules['apm_fifteen_minutes'] ) ) {
            $schedules['apm_fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Ogni 15 minuti', 'advanced-promo-mechanics' ),
            ];
        }
        return $schedules;
    }

    public function handle_product_save( int $post_id, $post, bool $update ) : void {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $this->sync_snapshot( $post_id, 0 );
    }

    public function handle_variation_save( int $variation_id, $post, bool $update ) : void {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) {
            return;
        }
        $parent_id = $variation->get_parent_id();
        $this->sync_snapshot( $parent_id ?: $variation_id, $variation_id );
    }

    public function handle_post_delete( int $post_id ) : void {
        global $wpdb;
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            return;
        }
        $table = $wpdb->prefix . 'apm_pricing_snapshot';
        if ( 'product' === $post->post_type ) {
            $wpdb->delete( $table, [ 'tenant_id' => $this->tenant_id, 'product_id' => $post_id ], [ '%s', '%d' ] );
        } else {
            $parent = (int) $post->post_parent;
            $wpdb->delete( $table, [ 'tenant_id' => $this->tenant_id, 'product_id' => $parent, 'variation_id' => $post_id ], [ '%s', '%d', '%d' ] );
        }
    }

    private function sync_snapshot( int $product_id, int $variation_id = 0 ) : void {
        global $wpdb;

        $product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $target_product_id = $product->get_parent_id() ?: $product->get_id();
        $target_variation  = $product->is_type( 'variation' ) ? $product->get_id() : 0;

        $cost       = get_post_meta( $product->get_id(), '_apm_cost', true );
        $min_margin = get_post_meta( $product->get_id(), '_apm_min_margin', true );

        if ( ! $cost && $target_variation && ! $cost ) {
            $cost = get_post_meta( $target_product_id, '_apm_cost', true );
        }
        if ( ! $min_margin && $target_variation && ! $min_margin ) {
            $min_margin = get_post_meta( $target_product_id, '_apm_min_margin', true );
        }

        $strategy = get_post_meta( $target_product_id, '_apm_pricing_strategy', true ) ?: 'margin_guard';
        $stock    = $product->get_stock_quantity();
        $currency = get_woocommerce_currency();

        $data = [
            'tenant_id'       => $this->tenant_id,
            'product_id'      => $target_product_id,
            'variation_id'    => $target_variation,
            'cost'            => $cost !== '' ? $cost : null,
            'min_margin'      => $min_margin !== '' ? $min_margin : null,
            'competitor_min'  => null,
            'competitor_avg'  => null,
            'stock'           => $stock,
            'strategy'        => $strategy,
            'currency'        => $currency,
            'snapshot_source' => 'product_meta',
        ];

        $wpdb->replace( $wpdb->prefix . 'apm_pricing_snapshot', $data );
    }

    public function run_repricing() : void {
        global $wpdb;

        $table        = $wpdb->prefix . 'apm_pricing_snapshot';
        $actions      = $wpdb->prefix . 'apm_price_actions';
        $batch_size   = (int) apply_filters( 'apm_repricing_batch', 50 );
        $rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY created_at DESC LIMIT %d", $this->tenant_id, $batch_size ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $product = $row->variation_id ? wc_get_product( (int) $row->variation_id ) : wc_get_product( (int) $row->product_id );
            if ( ! $product instanceof WC_Product ) {
                continue;
            }

            if ( 'publish' !== get_post_status( $product->get_id() ) ) {
                continue;
            }

            $context = new Pricing_Context( [
                'product_id'     => (int) $row->product_id,
                'variation_id'   => (int) $row->variation_id,
                'cost'           => $row->cost,
                'min_margin'     => $row->min_margin,
                'competitor_min' => $row->competitor_min,
                'competitor_avg' => $row->competitor_avg,
                'stock'          => $row->stock,
                'strategy'       => $row->strategy,
                'currency'       => $row->currency,
                'current_price'  => (float) $product->get_regular_price() ?: (float) $product->get_price(),
            ] );

            $decision = $this->decide_price( $context );
            if ( ! $decision ) {
                continue;
            }

            $this->apply_price( $product, $decision );

            $wpdb->insert( $actions, [
                'tenant_id'       => $this->tenant_id,
                'product_id'      => $context->product_id,
                'variation_id'    => $context->variation_id,
                'strategy'        => $context->strategy,
                'old_price'       => $context->current_price,
                'new_price'       => $decision->price,
                'computed_margin' => $decision->margin,
                'status'          => 'applied',
                'notes'           => $decision->notes,
            ], [ '%s', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s' ] );

            $this->logger->debug( 'Repriced product', [
                'product_id'   => $context->product_id,
                'variation_id' => $context->variation_id,
                'strategy'     => $context->strategy,
                'price'        => $decision->price,
            ] );

            // Refresh snapshot to include latest price/stock info.
            $this->sync_snapshot( $context->product_id, $context->variation_id );
        }
    }

    private function decide_price( Pricing_Context $context ) : ?Pricing_Decision {
        if ( empty( $this->strategies ) ) {
            return null;
        }
        foreach ( $this->strategies as $strategy ) {
            if ( $strategy->get_key() !== $context->strategy ) {
                continue;
            }
            if ( ! $strategy->supports( $context ) ) {
                continue;
            }
            return $strategy->decide( $context );
        }
        return null;
    }

    private function apply_price( WC_Product $product, Pricing_Decision $decision ) : void {
        $decimals  = wc_get_price_decimals();
        $new_price = wc_format_decimal( $decision->price, $decimals );

        $product->set_regular_price( $new_price );
        if ( $product->is_type( 'variation' ) ) {
            $product->save();
        } else {
            $product->save();
        }
    }

    private function register_strategy( Pricing_Strategy_Interface $strategy ) : void {
        $this->strategies[ $strategy->get_key() ] = $strategy;
    }

    public function filter_batch_size( int $default ) : int {
        return (int) ( $this->settings['batch_size'] ?? $default );
    }

    public function handle_settings_updated( array $settings ) : void {
        $this->settings = apm_get_repricer_settings();
        $this->maybe_schedule_event( true );
    }

    private function maybe_schedule_event( bool $force = false ) : void {
        $interval_slug      = $this->get_schedule_slug();
        $current_schedule   = wp_get_schedule( 'apm_run_repricing' );

        if ( $force || ( $current_schedule && $current_schedule !== $interval_slug ) ) {
            $this->clear_scheduled_events();
        }

        if ( ! wp_next_scheduled( 'apm_run_repricing' ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval_slug, 'apm_run_repricing' );
        }
    }

    private function clear_scheduled_events() : void {
        $timestamp = wp_next_scheduled( 'apm_run_repricing' );
        while ( false !== $timestamp ) {
            wp_unschedule_event( $timestamp, 'apm_run_repricing' );
            $timestamp = wp_next_scheduled( 'apm_run_repricing' );
        }
    }

    private function get_schedule_slug() : string {
        return ( isset( $this->settings['schedule'] ) && '15min' === $this->settings['schedule'] ) ? 'apm_fifteen_minutes' : 'apm_five_minutes';
    }
}
