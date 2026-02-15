<?php
namespace APM;

class Stock_Push_Strategy implements Pricing_Strategy_Interface {
    public function get_key() : string {
        return 'stock_push';
    }

    public function supports( Pricing_Context $context ) : bool {
        return $context->stock !== null && $context->stock > 0 && $context->cost !== null;
    }

    public function decide( Pricing_Context $context ) : ?Pricing_Decision {
        if ( ! $this->supports( $context ) ) {
            return null;
        }

        $threshold = (int) get_option( 'apm_stock_push_threshold', 100 );
        if ( $context->stock < $threshold ) {
            return null;
        }

        $max_discount_pct = (float) get_option( 'apm_stock_push_discount', 10 );
        $min_margin_pct   = (float) get_option( 'apm_stock_push_min_margin', 5 );

        $discount_factor = max( 0.0, min( $max_discount_pct, 90 ) ) / 100;
        $candidate_price = $context->current_price * ( 1 - $discount_factor );

        $min_price = $context->cost * ( 1 + ( $min_margin_pct / 100 ) );
        $new_price = max( $candidate_price, $min_price );

        if ( $new_price >= $context->current_price || abs( $context->current_price - $new_price ) < 0.01 ) {
            return null;
        }

        $margin = $this->calculate_margin_percent( $new_price, $context->cost );

        return new Pricing_Decision( $new_price, $margin, __( 'Stock push discount', 'advanced-promo-mechanics' ) );
    }

    private function calculate_margin_percent( float $price, float $cost ) : float {
        if ( $price <= 0 ) {
            return 0.0;
        }
        return max( 0.0, ( ( $price - $cost ) / $price ) * 100 );
    }
}
