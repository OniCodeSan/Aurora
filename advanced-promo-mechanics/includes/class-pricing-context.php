<?php
namespace APM;

class Pricing_Context {
    public int $product_id;
    public int $variation_id;
    public ?float $cost;
    public ?float $min_margin;
    public ?float $competitor_min;
    public ?float $competitor_avg;
    public ?int $stock;
    public string $currency;
    public string $strategy;
    public float $current_price;

    public function __construct( array $args ) {
        $this->product_id     = (int) ( $args['product_id'] ?? 0 );
        $this->variation_id   = (int) ( $args['variation_id'] ?? 0 );
        $this->cost           = null !== $args['cost'] ? (float) $args['cost'] : null;
        $this->min_margin     = null !== $args['min_margin'] ? (float) $args['min_margin'] : null;
        $this->competitor_min = null !== $args['competitor_min'] ? (float) $args['competitor_min'] : null;
        $this->competitor_avg = null !== $args['competitor_avg'] ? (float) $args['competitor_avg'] : null;
        $this->stock          = isset( $args['stock'] ) ? (int) $args['stock'] : null;
        $this->strategy       = $args['strategy'] ?? 'margin_guard';
        $this->currency       = $args['currency'] ?? get_woocommerce_currency();
        $this->current_price  = isset( $args['current_price'] ) ? (float) $args['current_price'] : 0.0;
    }
}
