<?php
namespace APM\Rules;

use WC_Cart;

class Rule_Three_Two extends Rule_Base {
    public function apply( WC_Cart $cart, bool $dry_run = false ) : array {
        $items = $this->collect_items( $cart );
        if ( count( $items ) < 3 ) {
            return $this->response();
        }
        usort( $items, static fn( $a, $b ) => $a['price'] <=> $b['price'] );
        $repeatable = ! empty( $this->rule['config']['repeatable'] );
        $sets       = $repeatable ? intdiv( count( $items ), 3 ) : 1;
        $sets       = max( 0, $sets );
        $discount   = 0.0;

        for ( $i = 0; $i < $sets; $i++ ) {
            $discount += $items[ $i ]['price'];
        }

        if ( $discount <= 0 ) {
            return $this->response();
        }

        if ( ! $dry_run ) {
            for ( $i = 0; $i < $sets; $i++ ) {
                $cart_key = $items[ $i ]['key'];
                $cart->cart_contents[ $cart_key ]['apm_discounted'][] = $this->rule['id'];
            }
        }

        $fees = [
            [
                'name'      => sprintf( __( 'Promo 3x2 (%s)', 'advanced-promo-mechanics' ), $this->rule['name'] ?? '' ),
                'amount'    => -1 * $discount,
                'taxable'   => false,
                'tax_class' => null,
            ],
        ];

        return $this->response( true, __( 'Hai ottenuto la promo 3x2', 'advanced-promo-mechanics' ), $fees );
    }

    private function collect_items( WC_Cart $cart ) : array {
        $eligible = [];
        foreach ( $cart->get_cart() as $key => $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product ) {
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
}
