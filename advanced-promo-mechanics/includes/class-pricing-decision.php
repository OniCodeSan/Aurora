<?php
namespace APM;

class Pricing_Decision {
    public float $price;
    public float $margin;
    public string $notes;

    public function __construct( float $price, float $margin, string $notes = '' ) {
        $this->price  = $price;
        $this->margin = $margin;
        $this->notes  = $notes;
    }
}
