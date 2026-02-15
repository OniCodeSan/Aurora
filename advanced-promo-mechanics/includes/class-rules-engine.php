<?php
namespace APM;

use WC_Cart;

class Rules_Engine {
    private Rules_Store $store;
    private Logger $logger;
    private array $applied = [];
    private bool $processing = false;

    public function __construct( Rules_Store $store, Logger $logger ) {
        $this->store  = $store;
        $this->logger = $logger;
    }

    public function apply_cart_adjustments( WC_Cart $cart ) : void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        if ( $this->processing ) {
            return;
        }

        $this->processing = true;

        try {
            $settings = get_option( 'apm_settings', [] );
            $dry_run  = ! empty( $settings['dry_run'] );

            $rules = $this->store->get_rules();
            $this->applied = [];

            foreach ( $rules as $rule ) {
                if ( ! $rule['enabled'] || ! $this->conditions_match( $rule, $cart ) ) {
                    continue;
                }
                $instance = $this->make_rule( $rule );
                if ( ! $instance ) {
                    continue;
                }
                $result = $instance->apply( $cart, $dry_run );
                if ( $result['applied'] ) {
                    $this->applied[] = $result;
                    if ( ! empty( $rule['config']['exclusive'] ) ) {
                        break;
                    }
                }
            }
        } finally {
            $this->processing = false;
        }
    }

    public function apply_cart_fees( WC_Cart $cart ) : void {
        foreach ( $this->applied as $result ) {
            foreach ( $result['fees'] as $fee ) {
                $cart->add_fee( $fee['name'], $fee['amount'], $fee['taxable'], $fee['tax_class'] );
            }
        }
    }

    public function invalidate_cart_cache() : void {
        $this->applied = [];
    }

    private function make_rule( array $rule ) : ?Rules\Rule_Base {
        return match ( $rule['type'] ) {
            'free_gift'   => new Rules\Rule_Free_Gift( $rule, $this->logger ),
            'bogo'        => new Rules\Rule_Bogo( $rule, $this->logger ),
            'three_two'   => new Rules\Rule_Three_Two( $rule, $this->logger ),
            'quantity'    => new Rules\Rule_Quantity_Discount( $rule, $this->logger ),
            'buyx_gety'   => new Rules\Rule_BuyX_GetY( $rule, $this->logger ),
            default       => null,
        };
    }

    private function conditions_match( array $rule, WC_Cart $cart ) : bool {
        $cond = $rule['conditions'];
        if ( ! empty( $cond['coupon'] ) && ! in_array( $cond['coupon'], $cart->get_applied_coupons(), true ) ) {
            return false;
        }

        $subtotal = (float) $cart->get_subtotal();
        if ( isset( $cond['min_subtotal'] ) && $subtotal < (float) $cond['min_subtotal'] ) {
            return false;
        }

        $qty = array_sum( wp_list_pluck( $cart->get_cart(), 'quantity' ) );
        if ( isset( $cond['min_qty'] ) && $qty < (int) $cond['min_qty'] ) {
            return false;
        }

        if ( ! empty( $cond['customer_roles'] ) ) {
            $user = wp_get_current_user();
            if ( empty( array_intersect( $cond['customer_roles'], (array) $user->roles ) ) ) {
                return false;
            }
        }

        $now = current_time( 'timestamp' );
        if ( ! empty( $rule['schedule_start'] ) && $now < strtotime( $rule['schedule_start'] ) ) {
            return false;
        }
        if ( ! empty( $rule['schedule_end'] ) && $now > strtotime( $rule['schedule_end'] ) ) {
            return false;
        }
        return true;
    }
}
