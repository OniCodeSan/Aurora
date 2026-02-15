<?php
namespace APM;

class Margin_Guard_Strategy implements Pricing_Strategy_Interface {
    public function get_key() : string {
        return 'margin_guard';
    }

    public function supports( Pricing_Context $context ) : bool {
        return $context->cost !== null;
    }

    public function decide( Pricing_Context $context ) : ?Pricing_Decision {
        if ( null === $context->cost ) {
            return null;
        }
        $min_margin = $context->min_margin ?? (float) get_option( 'apm_default_min_margin', 20 );
        $min_margin = max( 0.0, (float) $min_margin );
        $target_price = $context->cost * ( 1 + ( $min_margin / 100 ) );

        if ( $context->competitor_min && $context->competitor_min > 0 ) {
            // Manteniamo il prezzo almeno quanto la min margin, ma tentiamo di rimanere competitivi.
            $target_price = min( $target_price, $context->competitor_min * 0.99 );
            $target_price = max( $target_price, $context->cost * 1.01 );
        }

        $new_price = max( $target_price, $context->cost );
        $margin    = $this->calculate_margin_percent( $new_price, $context->cost );

        if ( abs( $new_price - $context->current_price ) < 0.01 ) {
            return null;
        }

        return new Pricing_Decision( $new_price, $margin, __( 'Margin guard adjustment', 'advanced-promo-mechanics' ) );
    }

    private function calculate_margin_percent( float $price, float $cost ) : float {
        if ( $price <= 0 ) {
            return 0.0;
        }
        return max( 0.0, ( ( $price - $cost ) / $price ) * 100 );
    }
}
