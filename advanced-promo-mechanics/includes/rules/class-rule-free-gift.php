<?php
namespace APM\Rules;

use WC_Cart;
use WC_Product;

class Rule_Free_Gift extends Rule_Base {
    public function apply( WC_Cart $cart, bool $dry_run = false ) : array {
        $config     = $this->rule['config'];
        $gift_ids   = array_map( 'absint', $config['gift_products'] ?? [] );
        $auto_add   = array_key_exists( 'auto_add', $config ) ? (bool) $config['auto_add'] : true;
        if ( empty( $gift_ids ) || ! $auto_add ) {
            return $this->response();
        }

        $max_gifts  = (int) ( $config['limit_per_order'] ?? 0 );
        $force      = ! empty( get_option( 'apm_settings', [] )['force_gifts'] );
        $added      = 0;

        foreach ( $gift_ids as $gift_id ) {
            if ( $max_gifts && $added >= $max_gifts ) {
                break;
            }
            if ( $this->gift_present( $cart, $gift_id ) ) {
                continue;
            }
            $product = wc_get_product( $gift_id );
            if ( ! $product || ! $product->is_in_stock() ) {
                continue;
            }
            if ( $dry_run ) {
                $added++;
                continue;
            }
            $cart->add_to_cart( $gift_id, 1, 0, [], [ 'apm_gift' => 1, 'apm_rule_id' => $this->rule['id'] ] );
            $added++;
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( ! empty( $cart_item['apm_gift'] ) ) {
                $cart->cart_contents[ $cart_item_key ]['data']->set_price( 0 );
            }
        }

        if ( $force ) {
            // If user removed gift but conditions still valid, re-add next calculation cycle.
            // This is already handled because we attempt to add each cycle when not present.
        }

        if ( $added > 0 ) {
            $this->logger->debug( 'Free gift applied', [ 'rule' => $this->rule['name'], 'added' => $added ] );
            add_filter( 'woocommerce_cart_item_price', [ $this, 'display_gift_price' ], 10, 3 );
            return $this->response( true, __( 'Hai ricevuto un omaggio', 'advanced-promo-mechanics' ) );
        }

        return $this->response();
    }

    public function display_gift_price( string $price_html, array $cart_item, string $cart_item_key ) : string {
        if ( ! empty( $cart_item['apm_gift'] ) ) {
            $price_html = __( 'Omaggio', 'advanced-promo-mechanics' );
        }
        return $price_html;
    }

    private function gift_present( WC_Cart $cart, int $product_id ) : bool {
        foreach ( $cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['apm_gift'] ) && (int) $cart_item['product_id'] === $product_id ) {
                return true;
            }
        }
        return false;
    }
}
