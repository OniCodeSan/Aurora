<?php
namespace APM\Rules;

use WC_Cart;

class Rule_Quantity_Discount extends Rule_Base {
    public function apply( WC_Cart $cart, bool $dry_run = false ) : array {
        $tiers    = $this->rule['config']['tiers'] ?? [];
        $discount = 0.0;

        foreach ( $cart->get_cart() as $key => $cart_item ) {
            $tier = $this->match_tier( $cart_item, $tiers );
            if ( ! $tier ) {
                continue;
            }
            $line_price = (float) $cart_item['line_subtotal'];
            $line_qty   = (int) $cart_item['quantity'];
            if ( 'percent' === $tier['discount_type'] ) {
                $discount += ( $line_price * ( (float) $tier['value'] / 100 ) );
            } else {
                $discount += ( (float) $tier['value'] * $line_qty );
            }
            if ( ! $dry_run ) {
                $cart->cart_contents[ $key ]['apm_discounted'][] = $this->rule['id'];
            }
        }

        if ( $discount <= 0 ) {
            return $this->response();
        }

        $fees = [
            [
                'name'      => sprintf( __( 'Sconto quantità (%s)', 'advanced-promo-mechanics' ), $this->rule['name'] ?? '' ),
                'amount'    => -1 * $discount,
                'taxable'   => false,
                'tax_class' => null,
            ],
        ];

        return $this->response( true, __( 'Hai ottenuto lo sconto quantità', 'advanced-promo-mechanics' ), $fees );
    }

    private function match_tier( array $cart_item, array $tiers ) : ?array {
        $qty = (int) $cart_item['quantity'];
        $product = $cart_item['data'];
        foreach ( $tiers as $tier ) {
            $from = (int) ( $tier['from'] ?? 0 );
            $to   = (int) ( $tier['to'] ?? 0 );
            if ( $qty < $from ) {
                continue;
            }
            if ( $to && $qty > $to ) {
                continue;
            }
            if ( ! empty( $tier['skus'] ) ) {
                $skus = array_map( 'trim', explode( ',', $tier['skus'] ) );
                if ( ! in_array( $product->get_sku(), $skus, true ) ) {
                    continue;
                }
            }
            return $tier;
        }
        return null;
    }
}
