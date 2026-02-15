<?php
namespace APM\Rules;

use APM\Logger;
use WC_Cart;

abstract class Rule_Base {
    protected array $rule;
    protected Logger $logger;

    public function __construct( array $rule, Logger $logger ) {
        $this->rule   = $rule;
        $this->logger = $logger;
    }

    /**
     * @return array{applied:bool,message:string,fees:array<int,array{ name:string, amount:float, taxable:bool, tax_class:?string }>} 
     */
    abstract public function apply( WC_Cart $cart, bool $dry_run = false ) : array;

    protected function response( bool $applied = false, string $message = '', array $fees = [] ) : array {
        return [
            'applied' => $applied,
            'message' => $message,
            'fees'    => $fees,
        ];
    }

    protected function eligible_items( WC_Cart $cart ) : array {
        $items = [];
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product ) {
                continue;
            }
            $items[ $cart_item_key ] = [
                'product'      => $product,
                'quantity'     => $cart_item['quantity'],
                'line_total'   => $product->get_price() * $cart_item['quantity'],
                'line_subtotal' => $cart_item['line_subtotal'],
                'sku'          => (string) $product->get_sku(),
            ];
        }
        return $items;
    }
}
