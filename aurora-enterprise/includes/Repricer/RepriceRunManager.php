<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Support\Logger;
use wpdb;

class RepriceRunManager {
    private Ops_Run_Manager $runs;
    private Logger $logger;
    private RepriceChunkProcessor $chunks;

    public function __construct( ?RepriceChunkProcessor $chunks = null ) {
        $this->runs = Ops_Run_Manager::instance();
        $this->logger = new Logger();
        $this->chunks = $chunks ?? new RepriceChunkProcessor();
    }

    /**
     * Minimal dry-run repricer (max 100 products) without partial/resume.
     * @param array<string,mixed> $payload
     */
    public function start( int $runId, array $payload = [] ) : void {
        $config = $this->config( $payload );
        global $wpdb;

        $ids = $this->chunks->fetch_next_ids( 0, $config['max_products'] );
        $decisionTable = $wpdb->prefix . 'aurora_reprice_decisions';

        $counters = [
            'override' => 0,
            'missing_cost' => 0,
            'invalid' => 0,
            'no_change' => 0,
            'floor_margin' => 0,
        ];

        foreach ( $ids as $productId ) {
            $decision = $this->decide( $productId, $config );
            $decision['run_id'] = $runId;
            $decision['created_at'] = current_time( 'mysql', true );
            $inserted = $this->insertDecision( $decisionTable, $decision );
            if ( $inserted ) {
                $counters[ $decision['rule_applied'] ] = ($counters[ $decision['rule_applied'] ] ?? 0) + 1;
            }
        }

        $summary = [
            'message' => 'repricer dry-run completed',
            'processed' => count( $ids ),
            'counters' => $counters,
        ];
        $this->runs->mark_success( $runId, $summary );
        $this->logger->info( 'repricer', 'repricer complete', [ 'run_id' => $runId ] + $summary );
    }

    /** @param array<string,mixed> $payload */
    private function config( array $payload ) : array {
        return [
            'max_products' => min( 100, isset( $payload['max_products'] ) ? max( 1, (int) $payload['max_products'] ) : 100 ),
            'min_margin_percent' => isset( $payload['min_margin_percent'] ) ? (float) $payload['min_margin_percent'] : 10.0,
            'min_margin_abs' => isset( $payload['min_margin_abs'] ) ? (float) $payload['min_margin_abs'] : 1.0,
        ];
    }

    private function decide( int $productId, array $config ) : array {
        $override = get_post_meta( $productId, 'aurora_price_override', true );
        $sku = get_post_meta( $productId, '_sku', true );
        $price_raw = get_post_meta( $productId, '_price', true );
        $price = $price_raw !== '' ? (float) $price_raw : null;
        $cost_raw = get_post_meta( $productId, '_aurora_cost', true );
        if ( $cost_raw === '' ) {
            $cost_raw = get_post_meta( $productId, '_cost', true );
        }
        $cost = $cost_raw !== '' ? (float) $cost_raw : null;

        $rule = 'no_change';
        $reason = null;
        $new_price = $price ?? 0.0;
        $margin_before = null;
        $margin_after = null;

        if ( (string) $override === '1' ) {
            $rule = 'override';
            $reason = 'override flag';
        } elseif ( null === $cost || $cost <= 0 ) {
            $rule = 'missing_cost';
            $reason = 'no cost';
        } elseif ( null === $price || $price <= 0 ) {
            $rule = 'invalid';
            $reason = 'price invalid';
        } else {
            $floor_percent = $cost * ( 1 + ( $config['min_margin_percent'] / 100 ) );
            $floor_abs = $cost + $config['min_margin_abs'];
            $floor = max( $floor_percent, $floor_abs );
            if ( $price < $floor ) {
                $new_price = $floor;
                $rule = 'floor_margin';
                $reason = 'below floor';
            } else {
                $new_price = $price;
            }
            if ( $price > 0 ) {
                $margin_before = ( $price - $cost ) / $price;
            }
            if ( $new_price > 0 ) {
                $margin_after = ( $new_price - $cost ) / $new_price;
            }
        }

        return [
            'product_id' => $productId,
            'variation_id' => null,
            'sku' => $sku ?: null,
            'currency' => 'EUR',
            'old_price' => $price,
            'new_price' => $new_price,
            'cost' => $cost,
            'margin_before' => $margin_before,
            'margin_after' => $margin_after,
            'rule_applied' => $rule,
            'reason' => $reason,
            'applied' => 0,
        ];
    }

    private function insertDecision( string $table, array $data ) : bool {
        global $wpdb;
        $inserted = $wpdb->insert( $table, $data );
        if ( false === $inserted ) {
            return false; // likely duplicate
        }
        return true;
    }
}

