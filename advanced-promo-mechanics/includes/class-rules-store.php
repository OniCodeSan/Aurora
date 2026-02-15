<?php
namespace APM;

use WP_Post;

class Rules_Store {
    private string $cache_key = 'apm_rules_cache';
    private Logger $logger;

    public function __construct( Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_rules() : array {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $posts = get_posts( [
            'post_type'      => 'apm_rule',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'meta_value_num',
            'meta_key'       => 'apm_priority',
            'order'          => 'ASC',
        ] );

        $rules = [];
        foreach ( $posts as $post ) {
            $rules[] = $this->hydrate_rule( $post );
        }
        set_transient( $this->cache_key, $rules, HOUR_IN_SECONDS );
        return $rules;
    }

    public function hydrate_rule( WP_Post $post ) : array {
        $meta = $this->get_rule_meta( $post->ID );
        $meta['id']      = $post->ID;
        $meta['name']    = $post->post_title;
        $meta['enabled'] = ! empty( $meta['enabled'] );
        return $meta;
    }

    public function get_rule_meta( int $rule_id ) : array {
        $defaults = [
            'enabled'        => 0,
            'type'           => 'free_gift',
            'priority'       => 10,
            'conditions'     => [],
            'config'         => [],
            'schedule_start' => '',
            'schedule_end'   => '',
        ];
        $stored = get_post_meta( $rule_id, 'apm_rule', true );
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }
        return wp_parse_args( $stored, $defaults );
    }

    public function save_rule_meta( int $rule_id, array $payload ) : void {
        $sanitized = [
            'enabled'        => isset( $payload['enabled'] ) ? 1 : 0,
            'type'           => sanitize_key( $payload['type'] ?? 'free_gift' ),
            'priority'       => isset( $payload['priority'] ) ? absint( $payload['priority'] ) : 10,
            'conditions'     => $this->sanitize_conditions( $payload['conditions'] ?? [] ),
            'config'         => $this->sanitize_config( $payload['config'] ?? [] ),
            'schedule_start' => sanitize_text_field( $payload['schedule_start'] ?? '' ),
            'schedule_end'   => sanitize_text_field( $payload['schedule_end'] ?? '' ),
        ];

        update_post_meta( $rule_id, 'apm_rule', $sanitized );
        update_post_meta( $rule_id, 'apm_priority', $sanitized['priority'] );
        $this->flush_cache();
    }

    public function flush_cache() : void {
        delete_transient( $this->cache_key );
        $this->logger->debug( 'Flushed rules cache' );
    }

    private function sanitize_conditions( array $conditions ) : array {
        $fields = [ 'min_subtotal', 'min_qty' ];
        foreach ( $fields as $key ) {
            if ( isset( $conditions[ $key ] ) ) {
                $conditions[ $key ] = (float) $conditions[ $key ];
            }
        }
        $conditions['include_skus']     = isset( $conditions['include_skus'] ) ? $this->sanitize_text_list( $conditions['include_skus'] ) : [];
        $conditions['exclude_skus']     = isset( $conditions['exclude_skus'] ) ? $this->sanitize_text_list( $conditions['exclude_skus'] ) : [];
        $conditions['include_terms']    = isset( $conditions['include_terms'] ) ? array_map( 'absint', (array) $conditions['include_terms'] ) : [];
        $conditions['exclude_terms']    = isset( $conditions['exclude_terms'] ) ? array_map( 'absint', (array) $conditions['exclude_terms'] ) : [];
        $conditions['include_tags']     = isset( $conditions['include_tags'] ) ? array_map( 'absint', (array) $conditions['include_tags'] ) : [];
        $conditions['exclude_tags']     = isset( $conditions['exclude_tags'] ) ? array_map( 'absint', (array) $conditions['exclude_tags'] ) : [];
        $conditions['customer_roles']   = isset( $conditions['customer_roles'] ) ? array_map( 'sanitize_key', (array) $conditions['customer_roles'] ) : [];
        $conditions['coupon']           = isset( $conditions['coupon'] ) ? sanitize_text_field( $conditions['coupon'] ) : '';
        return $conditions;
    }

    private function sanitize_config( array $config ) : array {
        $config['mode']               = sanitize_key( $config['mode'] ?? '' );
        $config['repeatable']         = ! empty( $config['repeatable'] ) ? 1 : 0;
        $config['limit_per_order']    = isset( $config['limit_per_order'] ) ? absint( $config['limit_per_order'] ) : 0;
        $config['exclude_discounted'] = ! empty( $config['exclude_discounted'] ) ? 1 : 0;
        $config['exclusive']          = ! empty( $config['exclusive'] ) ? 1 : 0;
        $config['auto_add']           = array_key_exists( 'auto_add', $config ) ? ( ! empty( $config['auto_add'] ) ? 1 : 0 ) : 1;
        $config['choice_mode']        = ! empty( $config['choice_mode'] ) ? 1 : 0;

        if ( isset( $config['gift_products'] ) ) {
            $raw = is_array( $config['gift_products'] ) ? implode( ',', $config['gift_products'] ) : $config['gift_products'];
            $config['gift_products'] = array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
        } else {
            $config['gift_products'] = [];
        }

        $config['buy_qty']  = isset( $config['buy_qty'] ) ? max( 1, absint( $config['buy_qty'] ) ) : 1;
        $config['gift_qty'] = isset( $config['gift_qty'] ) ? max( 1, absint( $config['gift_qty'] ) ) : 1;

        if ( isset( $config['tiers_raw'] ) ) {
            $tiers = json_decode( wp_unslash( $config['tiers_raw'] ), true );
            $config['tiers'] = is_array( $tiers ) ? $tiers : [];
            unset( $config['tiers_raw'] );
        }

        $config['message_cart']     = isset( $config['message_cart'] ) ? wp_kses_post( $config['message_cart'] ) : '';
        $config['message_checkout'] = isset( $config['message_checkout'] ) ? wp_kses_post( $config['message_checkout'] ) : '';

        return $config;
    }

    private function sanitize_text_list( $value ) : array {
        $raw = is_array( $value ) ? $value : explode( ',', (string) $value );
        $raw = array_filter( array_map( 'trim', $raw ) );
        return array_map( 'sanitize_text_field', $raw );
    }
}
