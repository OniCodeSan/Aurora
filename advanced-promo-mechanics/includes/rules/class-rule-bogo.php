<?php
namespace APM\Rules;

use WC_Cart;

class Rule_Bogo extends Rule_Base {
    public function apply( WC_Cart $cart, bool $dry_run = false ) : array {
        $items = $this->collect_items( $cart );
        if ( count( $items ) < 2 ) {
            return $this->response();
        }

        usort( $items, static fn( $a, $b ) => $a['price'] <=> $b['price'] );
        $repeatable = ! empty( $this->rule['config']['repeatable'] );
        $pairs      = $repeatable ? intdiv( count( $items ), 2 ) : 1;
        $pairs      = max( 0, $pairs );
        $discount   = 0.0;

        for ( $i = 0; $i < $pairs; $i++ ) {
            $discount += $items[ $i ]['price'];
        }

        if ( $discount <= 0 ) {
            return $this->response();
        }

        if ( ! $dry_run ) {
            foreach ( $items as $index => $cart_item ) {
                if ( $index >= $pairs ) {
                    break;
                }
                $cart_key = $cart_item['key'];
                $cart->cart_contents[ $cart_key ]['apm_discounted'][] = $this->rule['id'];
            }
        }

        $fees = [
            [
                'name'      => sprintf( __( 'Promo: meno caro gratis (%s)', 'advanced-promo-mechanics' ), $this->rule['name'] ?? '' ),
                'amount'    => -1 * $discount,
                'taxable'   => false,
                'tax_class' => null,
            ],
        ];

        return $this->response( true, __( 'Hai ottenuto la promo 2x1 meno caro gratis', 'advanced-promo-mechanics' ), $fees );
    }

    private function collect_items( WC_Cart $cart ) : array {
        $eligible = [];
        foreach ( $cart->get_cart() as $key => $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product || ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
                continue;
            }
            if ( ! $this->matches_filters( $product ) ) {
                continue;
            }
            $price = (float) $product->get_price();
            $qty   = (int) $cart_item['quantity'];
            for ( $i = 0; $i < $qty; $i++ ) {
                $eligible[] = [ 'price' => $price, 'key' => $key ];
            }
        }
        return $eligible;
    }

    private function matches_filters( $product ) : bool {
        $config = $this->rule['conditions'];
        $include_skus = $config['include_skus'] ?? [];
        $exclude_skus = $config['exclude_skus'] ?? [];

        if ( ! empty( $include_skus ) && ! in_array( $product->get_sku(), $include_skus, true ) ) {
            return false;
        }
        if ( in_array( $product->get_sku(), $exclude_skus, true ) ) {
            return false;
        }
        return true;
    }
}
