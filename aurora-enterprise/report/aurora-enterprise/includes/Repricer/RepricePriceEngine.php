<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

/**
 * Deterministic pricing engine used by repricer runs and golden tests.
 */
class RepricePriceEngine {
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function evaluate( array $input, array $config ) : array {
        $oldPrice    = $this->nullable_float( $input['old_price'] ?? null );
        $cost        = $this->nullable_float( $input['cost'] ?? null );
        $competitor  = $this->first_float( $input['competitor_price'] ?? null, $config['competitor_price'] ?? null );
        $minPrice    = $this->first_float( $config['min_price'] ?? null, $input['min_price'] ?? null );
        $maxPrice    = $this->first_float( $config['max_price'] ?? null, $input['max_price'] ?? null );
        $mapPrice    = $this->first_float( $config['map_price'] ?? null, $input['map_price'] ?? null );
        $override    = (string) ( $input['override'] ?? '' ) === '1';
        $strategy    = $this->strategy_key( $config['strategy'] ?? 'maintain_margin' );
        $marginMode  = $this->margin_mode( $config['margin_mode'] ?? 'clamp' );
        $strategyReasonCode = $this->clean_string( (string) ( $config['strategy_reason_code'] ?? '' ) );
        $candidateOverride = $this->nullable_float( $config['candidate_price_override'] ?? null );

        $result = [
            'rule_applied'    => 'no_change',
            'reason_code'     => 'no_change',
            'reason_codes'    => [ 'no_change' ],
            'strategy_key'    => $strategy,
            'strategy_rule_id'=> isset( $config['strategy_rule_id'] ) ? (string) $config['strategy_rule_id'] : null,
            'old_price'       => $oldPrice,
            'candidate_price' => $oldPrice,
            'clamped_price'   => $oldPrice,
            'rounded_price'   => $oldPrice,
            'new_price'       => $oldPrice,
            'delta_pct'       => 0.0,
            'cost'            => $cost,
            'competitor_price'=> $competitor,
            'min_price'       => $minPrice,
            'max_price'       => $maxPrice,
            'map_price'       => $mapPrice,
            'margin_before'   => null,
            'margin_after'    => null,
            'audit_json'      => null,
        ];

        if ( $oldPrice !== null && $oldPrice > 0 && $cost !== null && $cost > 0 ) {
            $result['margin_before'] = $this->round4( ( $oldPrice - $cost ) / $oldPrice );
        }

        if ( $override ) {
            $result['rule_applied'] = 'override';
            $result['reason_code']  = 'override_flag';
            $result['reason_codes'] = [ 'override_flag' ];
            $result['audit_json']   = $this->encode_json( [ 'blocked' => true ] );
            return $result;
        }
        if ( $cost === null || $cost <= 0 ) {
            $result['rule_applied'] = 'missing_cost';
            $result['reason_code']  = 'missing_cost';
            $result['reason_codes'] = [ 'missing_cost' ];
            $result['audit_json']   = $this->encode_json( [ 'blocked' => true ] );
            return $result;
        }
        if ( $oldPrice === null || $oldPrice <= 0 ) {
            $result['rule_applied'] = 'invalid';
            $result['reason_code']  = 'invalid_price';
            $result['reason_codes'] = [ 'invalid_price' ];
            $result['audit_json']   = $this->encode_json( [ 'blocked' => true ] );
            return $result;
        }

        $candidate = $oldPrice;
        $reasons   = [];

        if ( null !== $candidateOverride && $candidateOverride > 0 ) {
            $candidate = $candidateOverride;
            $reasons[] = '' !== $strategyReasonCode ? $strategyReasonCode : 'strategy_manual';
        } elseif ( 'match_competitor' === $strategy || 'competitor' === $strategy ) {
            if ( $competitor !== null && $competitor > 0 ) {
                $candidate = $competitor;
                $reasons[] = 'strategy_match_competitor';
            } else {
                $reasons[] = 'competitor_missing';
            }
        } elseif ( 'beat_competitor' === $strategy ) {
            if ( $competitor !== null && $competitor > 0 ) {
                $deltaAbs = max( 0.0, (float) ( $config['beat_delta_abs'] ?? 0.0 ) );
                $deltaPct = max( 0.0, (float) ( $config['beat_delta_pct'] ?? 0.0 ) );
                $candidateAbs = $competitor - $deltaAbs;
                $candidatePct = $deltaPct > 0 ? ( $competitor * ( 1.0 - ( $deltaPct / 100.0 ) ) ) : $competitor;
                $candidate = min( $candidateAbs, $candidatePct );
                $reasons[] = 'strategy_beat_competitor';
            } else {
                $reasons[] = 'competitor_missing';
            }
        } else {
            $targetPct = $this->nullable_float( $config['target_margin_percent'] ?? null );
            $targetAbs = $this->nullable_float( $config['target_margin_abs'] ?? null );
            if ( $targetPct !== null && $targetPct > 0 && $targetPct < 100 ) {
                $candidate = max( $candidate, ( $cost / ( 1.0 - ( $targetPct / 100.0 ) ) ) );
                $reasons[] = 'margin' === $strategy ? 'strategy_margin' : 'strategy_maintain_margin';
            }
            if ( $targetAbs !== null && $targetAbs > 0 ) {
                $candidate = max( $candidate, ( $cost + $targetAbs ) );
                $reasons[] = 'margin' === $strategy ? 'strategy_margin' : 'strategy_maintain_margin';
            }
            if ( empty( $reasons ) ) {
                $reasons[] = 'margin' === $strategy ? 'strategy_margin' : 'strategy_maintain_margin';
            }
        }

        $candidate = $this->round4( max( 0.0, $candidate ) );
        $result['candidate_price'] = $candidate;

        $marginFloorPercent = $cost * ( 1.0 + ( max( 0.0, (float) ( $config['min_margin_percent'] ?? 0.0 ) ) / 100.0 ) );
        $marginFloorAbs     = $cost + max( 0.0, (float) ( $config['min_margin_abs'] ?? 0.0 ) );
        $marginFloor        = $this->round4( max( $marginFloorPercent, $marginFloorAbs ) );

        $effectiveMin = $marginFloor;
        $mapAsEffectiveMin = false;
        if ( $mapPrice !== null && $mapPrice > 0 ) {
            $effectiveMin = max( $effectiveMin, $mapPrice );
            if ( $effectiveMin === $mapPrice ) {
                $mapAsEffectiveMin = true;
            }
        }
        if ( $minPrice !== null && $minPrice > 0 ) {
            if ( $minPrice > $effectiveMin ) {
                $mapAsEffectiveMin = false;
            }
            $effectiveMin = max( $effectiveMin, $minPrice );
        }

        $clamped = $candidate;
        $blocked = false;
        if ( $clamped < $marginFloor ) {
            if ( 'block' === $marginMode ) {
                $blocked = true;
                $reasons[] = 'floor_margin_block';
                $clamped = $oldPrice;
            } else {
                $clamped = $marginFloor;
                $reasons[] = 'floor_margin_clamp';
            }
        }

        if ( ! $blocked ) {
            if ( $clamped < $effectiveMin ) {
                $clamped = $effectiveMin;
                $reasons[] = $mapAsEffectiveMin ? 'map_price_clamp' : 'min_price_clamp';
            }
            if ( $maxPrice !== null && $maxPrice > 0 && $clamped > $maxPrice ) {
                $clamped = $maxPrice;
                $reasons[] = 'max_price_clamp';
            }
        }

        if ( ! $blocked ) {
            $maxRaise = max( 0.0, (float) ( $config['max_raise_pct'] ?? 0.0 ) );
            $maxDrop  = max( 0.0, (float) ( $config['max_drop_pct'] ?? 0.0 ) );
            if ( $maxRaise > 0 ) {
                $raiseCap = $oldPrice * ( 1.0 + ( $maxRaise / 100.0 ) );
                if ( $clamped > $raiseCap ) {
                    $clamped = $raiseCap;
                    $reasons[] = 'max_raise_clamp';
                }
            }
            if ( $maxDrop > 0 ) {
                $dropFloor = $oldPrice * ( 1.0 - ( $maxDrop / 100.0 ) );
                if ( $clamped < $dropFloor ) {
                    $clamped = $dropFloor;
                    $reasons[] = 'max_drop_clamp';
                }
            }
        }

        $clamped = $this->round4( max( 0.0, $clamped ) );
        $result['clamped_price'] = $clamped;

        $rounded = $clamped;
        if ( ! $blocked ) {
            $roundingMode = strtolower( (string) ( $config['rounding_mode'] ?? 'none' ) );
            $roundingStep = max( 0.0, (float) ( $config['rounding_step'] ?? 0.0 ) );
            $rounded = $this->apply_rounding( $clamped, $roundingMode, $roundingStep );
            if ( ! $this->same_price( $rounded, $clamped ) ) {
                $reasons[] = 'rounded';
            }
            if ( $rounded < $effectiveMin ) {
                $rounded = $effectiveMin;
                $reasons[] = $mapAsEffectiveMin ? 'post_round_map_clamp' : 'post_round_min_clamp';
            }
            if ( $maxPrice !== null && $maxPrice > 0 && $rounded > $maxPrice ) {
                $rounded = $maxPrice;
                $reasons[] = 'post_round_max_clamp';
            }
        }

        $rounded = $this->round4( max( 0.0, $rounded ) );
        $result['rounded_price'] = $rounded;
        $result['new_price'] = $blocked ? $oldPrice : $rounded;

        if ( $result['new_price'] !== null && $result['new_price'] > 0 ) {
            $result['margin_after'] = $this->round4( ( $result['new_price'] - $cost ) / $result['new_price'] );
        }
        if ( $oldPrice > 0 ) {
            $result['delta_pct'] = $this->round4( ( ( $result['new_price'] - $oldPrice ) / $oldPrice ) * 100.0 );
        }

        $rule = $strategy;
        if ( in_array( 'floor_margin_block', $reasons, true ) || in_array( 'floor_margin_clamp', $reasons, true ) ) {
            $rule = 'floor_margin';
        }
        if ( $this->same_price( $result['new_price'], $oldPrice ) && ! in_array( 'floor_margin_block', $reasons, true ) ) {
            $rule = 'no_change';
        }

        $reasonCodes = array_values( array_unique( array_filter( $reasons ) ) );
        if ( $this->same_price( $result['new_price'], $oldPrice ) && count( $reasonCodes ) === 1 && $reasonCodes[0] === 'strategy_maintain_margin' ) {
            $reasonCodes = [ 'no_change' ];
        }
        if ( empty( $reasonCodes ) ) {
            $reasonCodes = [ 'no_change' ];
        }

        $result['rule_applied'] = $rule;
        $result['reason_code']  = $this->primary_reason_code( $reasonCodes );
        $result['reason_codes'] = $reasonCodes;
        $result['audit_json']   = $this->encode_json(
            [
                'strategy'        => $strategy,
                'margin_mode'     => $marginMode,
                'margin_floor'    => $marginFloor,
                'effective_min'   => $effectiveMin,
                'blocked'         => $blocked,
                'rounding_mode'   => strtolower( (string) ( $config['rounding_mode'] ?? 'none' ) ),
                'rounding_step'   => max( 0.0, (float) ( $config['rounding_step'] ?? 0.0 ) ),
                'max_raise_pct'   => max( 0.0, (float) ( $config['max_raise_pct'] ?? 0.0 ) ),
                'max_drop_pct'    => max( 0.0, (float) ( $config['max_drop_pct'] ?? 0.0 ) ),
                'reason_codes'    => $reasonCodes,
            ]
        );

        return $result;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function encode_json( array $value ) : string {
        if ( function_exists( 'wp_json_encode' ) ) {
            return (string) wp_json_encode( $value );
        }
        return (string) json_encode( $value );
    }

    private function strategy_key( string $value ) : string {
        $clean = strtolower( trim( $value ) );
        if ( in_array( $clean, [ 'maintain_margin', 'margin', 'match_competitor', 'beat_competitor', 'manual', 'markup', 'competitor' ], true ) ) {
            return $clean;
        }
        return 'maintain_margin';
    }

    private function margin_mode( string $value ) : string {
        return strtolower( trim( $value ) ) === 'block' ? 'block' : 'clamp';
    }

    private function nullable_float( $value ) : ?float {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return (float) $value;
    }

    private function first_float( ...$values ) : ?float {
        foreach ( $values as $value ) {
            $parsed = $this->nullable_float( $value );
            if ( null !== $parsed ) {
                return $parsed;
            }
        }
        return null;
    }

    private function apply_rounding( float $value, string $mode, float $step ) : float {
        $clean = strtolower( trim( $mode ) );
        if ( in_array( $clean, [ '.99', '99' ], true ) ) {
            return $this->round_to_psychological_ending( $value, 0.99 );
        }
        if ( in_array( $clean, [ '.49', '49' ], true ) ) {
            return $this->round_to_psychological_ending( $value, 0.49 );
        }
        if ( 'step' === $clean && $step > 0 ) {
            return round( round( $value / $step ) * $step, 4 );
        }
        return round( $value, 4 );
    }

    private function round_to_psychological_ending( float $value, float $ending ) : float {
        if ( $value < 1.0 ) {
            return round( $value, 4 );
        }
        $base = (int) floor( $value );
        $lower = max( 0.0, ( $base + $ending ) );
        $upper = max( 0.0, ( $base + 1 + $ending ) );
        $lowerDiff = abs( $value - $lower );
        $upperDiff = abs( $upper - $value );
        if ( $lowerDiff <= $upperDiff ) {
            return round( $lower, 4 );
        }
        return round( $upper, 4 );
    }

    private function same_price( ?float $a, ?float $b ) : bool {
        if ( null === $a && null === $b ) {
            return true;
        }
        if ( null === $a || null === $b ) {
            return false;
        }
        return abs( $a - $b ) < 0.00005;
    }

    private function round4( float $value ) : float {
        return round( $value, 4 );
    }

    private function clean_string( string $value ) : string {
        if ( function_exists( 'sanitize_text_field' ) ) {
            return (string) sanitize_text_field( $value );
        }
        return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '' );
    }

    /**
     * @param array<int,string> $reasonCodes
     */
    private function primary_reason_code( array $reasonCodes ) : string {
        $priority = [
            'floor_margin_block',
            'floor_margin_clamp',
            'map_price_clamp',
            'min_price_clamp',
            'max_price_clamp',
            'max_raise_clamp',
            'max_drop_clamp',
            'post_round_map_clamp',
            'post_round_min_clamp',
            'post_round_max_clamp',
            'competitor_missing',
            'rounded',
            'strategy_beat_competitor',
            'strategy_match_competitor',
            'strategy_markup',
            'strategy_manual_override',
            'strategy_manual_keep',
            'strategy_manual',
            'strategy_margin',
            'strategy_maintain_margin',
            'no_change',
        ];
        foreach ( $priority as $code ) {
            if ( in_array( $code, $reasonCodes, true ) ) {
                return $code;
            }
        }
        return $reasonCodes[0] ?? 'no_change';
    }
}
