<?php
namespace APM\Rules;

use WC_Cart;

class Rule_BuyX_GetY extends Rule_Base {
    public function apply( WC_Cart $cart, bool $dry_run = false ) : array {
        $config      = $this->rule['config'];
        $buy_qty     = max( 1, (int) ( $config['buy_qty'] ?? 1 ) );
        $gift_qty    = max( 1, (int) ( $config['gift_qty'] ?? 1 ) );
        $gift_ids    = array_map( 'absint', $config['gift_products'] ?? [] );
        $limit       = (int) ( $config['limit_per_order'] ?? 0 );
        $auto_add    = array_key_exists( 'auto_add', $config ) ? (bool) $config['auto_add'] : true;
        $eligible_qty = $this->count_eligible_items( $cart );

        if ( empty( $gift_ids ) || $eligible_qty < $buy_qty || ! $auto_add ) {
            return $this->response();
        }

        $bundles = intdiv( $eligible_qty, $buy_qty );
        if ( $limit > 0 ) {
            $bundles = min( $bundles, $limit );
        }

        $added = 0;
        foreach ( $gift_ids as $gift_id ) {
            for ( $i = 0; $i < $bundles * $gift_qty; $i++ ) {
                if ( $dry_run ) {
                    $added++;
                    continue;
                }
                $product = wc_get_product( $gift_id );
                if ( ! $product || ! $product->is_in_stock() ) {
                    continue 2;
                }
                $cart->add_to_cart( $gift_id, 1, 0, [], [ 'apm_gift' => 1, 'apm_rule_id' => $this->rule['id'], 'apm_buyx_gety' => 1 ] );
                $added++;
            }
        }

        if ( $added > 0 ) {
            foreach ( $cart->get_cart() as $key => $item ) {
                if ( ! empty( $item['apm_buyx_gety'] ) ) {
                    $cart->cart_contents[ $key ]['data']->set_price( 0 );
                }
            }
            return $this->response( true, __( 'Promo compra X regala Y attiva', 'advanced-promo-mechanics' ) );
        }

        return $this->response();
    }

    private function count_eligible_items( WC_Cart $cart ) : int {
        $conditions  = $this->rule['conditions'];
        $include_sku = array_filter( $conditions['include_skus'] ?? [] );
        $exclude_sku = array_filter( $conditions['exclude_skus'] ?? [] );
        $qty = 0;
        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'];
            if ( ! $product ) {
                continue;
            }
            $sku = (string) $product->get_sku();
            if ( $include_sku && ! in_array( $sku, $include_sku, true ) ) {
                continue;
            }
            if ( in_array( $sku, $exclude_sku, true ) ) {
                continue;
            }
            $qty += (int) $item['quantity'];
        }
        return $qty;
    }
}
