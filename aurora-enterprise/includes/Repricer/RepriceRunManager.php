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
    private RepriceLockManager $lock;
    private wpdb $db;

    public function __construct( ?RepriceChunkProcessor $chunks = null, ?RepriceLockManager $lock = null ) {
        $this->runs   = Ops_Run_Manager::instance();
        $this->logger = new Logger();
        $this->chunks = $chunks ?? new RepriceChunkProcessor();
        $this->lock   = $lock ?? new RepriceLockManager();
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function start( int $runId, array $payload = [] ) : void {
        $config = $this->config( $payload );
        $owner  = wp_generate_uuid4();
        $startedAt = microtime( true );

        if ( ! $this->lock->acquire( $owner ) ) {
            $this->handle_lock_busy( $runId, $payload );
            return;
        }

        if ( ! $this->runs->mark_running( $runId ) ) {
            $this->lock->release( $owner );
            return;
        }

        $progress = $this->load_or_create_progress( $runId );
        $counters = [
            'override'     => 0,
            'missing_cost' => 0,
            'invalid'      => 0,
            'no_change'    => 0,
            'floor_margin' => 0,
        ];

        $timebox   = (int) $config['timebox_seconds'];
        $memGuard  = (float) $config['memory_guard_ratio'];
        $chunkSize = (int) $config['chunk_size'];
        $max       = (int) $config['max_products'];

        $processed = (int) $progress['processed_count'];
        $updated   = (int) $progress['updated_count'];
        $lastId    = (int) $progress['last_product_id'];

        $this->logger->info( 'repricer', 'repricer start', [ 'run_id' => $runId, 'owner' => $owner, 'processed' => $processed ] );

        while ( true ) {
            $elapsed = microtime( true ) - $startedAt;
            if ( $elapsed >= $timebox || $this->memory_guard_triggered( $memGuard ) ) {
                $this->partial( $runId, $owner, $processed, $updated, $counters, $payload );
                return;
            }

            $remaining = $max - $processed;
            if ( $remaining <= 0 ) {
                $this->complete( $runId, $owner, $processed, $updated, $counters );
                return;
            }

            $limit = min( $chunkSize, $remaining );
            $ids   = $this->chunks->fetch_next_ids( $lastId, $limit );
            if ( empty( $ids ) ) {
                $this->complete( $runId, $owner, $processed, $updated, $counters );
                return;
            }

            foreach ( $ids as $productId ) {
                $decision = $this->decide( $productId, $config );
                $decision['run_id']     = $runId;
                $decision['created_at'] = current_time( 'mysql', true );
                if ( $this->insert_decision( $decision ) ) {
                    $processed++;
                    $counters[ $decision['rule_applied'] ] = ( $counters[ $decision['rule_applied'] ] ?? 0 ) + 1;
                    if ( 'floor_margin' === $decision['rule_applied'] ) {
                        $updated++;
                    }
                }
                $lastId = $productId;
            }

            $this->update_progress( $runId, [
                'status'          => 'running',
                'last_product_id' => $lastId,
                'processed_count' => $processed,
                'updated_count'   => $updated,
            ] );
        }
    }

    /** @param array<string,mixed> $payload */
    private function config( array $payload ) : array {
        $optChunk = (int) get_option( 'aurora_reprice_chunk_size', 0 );
        return [
            'max_products'       => isset( $payload['max_products'] ) ? max( 1, (int) $payload['max_products'] ) : 10000,
            'chunk_size'         => isset( $payload['chunk_size'] ) ? max( 1, (int) $payload['chunk_size'] ) : ( $optChunk > 0 ? $optChunk : 500 ),
            'min_margin_percent' => isset( $payload['min_margin_percent'] ) ? (float) $payload['min_margin_percent'] : 10.0,
            'min_margin_abs'     => isset( $payload['min_margin_abs'] ) ? (float) $payload['min_margin_abs'] : 1.0,
            'timebox_seconds'    => isset( $payload['timebox_seconds'] ) ? max( 1, (int) $payload['timebox_seconds'] ) : 90,
            'memory_guard_ratio' => isset( $payload['memory_guard_ratio'] ) ? (float) $payload['memory_guard_ratio'] : 0.70,
        ];
    }

    private function handle_lock_busy( int $runId, array $payload ) : void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::error( 'Lock busy: repricer run already in progress' );
        }
        $this->runs->mark_partial( $runId, [ 'message' => 'lock busy', 'payload' => $payload ] );
        $this->reschedule( $runId, $payload );
    }

    private function load_or_create_progress( int $runId ) : array {
        $table = $this->db->prefix . 'aurora_reprice_progress';
        $row   = $this->db->get_row(
            $this->db->prepare( "SELECT * FROM {$table} WHERE run_id = %d LIMIT 1", $runId ),
            ARRAY_A
        );
        if ( is_array( $row ) ) {
            return $row;
        }
        $now = current_time( 'mysql', true );
        $this->db->insert(
            $table,
            [
                'run_id'          => $runId,
                'status'          => 'running',
                'last_product_id' => 0,
                'processed_count' => 0,
                'updated_count'   => 0,
                'started_at'      => $now,
                'updated_at'      => $now,
            ]
        );
        return [
            'run_id'          => $runId,
            'status'          => 'running',
            'last_product_id' => 0,
            'processed_count' => 0,
            'updated_count'   => 0,
            'started_at'      => $now,
            'updated_at'      => $now,
        ];
    }

    private function update_progress( int $runId, array $fields ) : void {
        $table = $this->db->prefix . 'aurora_reprice_progress';
        $fields['updated_at'] = current_time( 'mysql', true );
        $this->db->update( $table, $fields, [ 'run_id' => $runId ] );
    }

    private function partial( int $runId, string $owner, int $processed, int $updated, array $counters, array $payload ) : void {
        $this->update_progress( $runId, [
            'status'          => 'partial',
            'processed_count' => $processed,
            'updated_count'   => $updated,
        ] );
        $summary = [
            'message'   => 'repricer partial',
            'processed' => $processed,
            'updated'   => $updated,
            'counters'  => $counters,
        ];
        $this->runs->mark_partial( $runId, $summary );
        $this->logger->info( 'repricer', 'repricer partial', [ 'run_id' => $runId ] + $summary );
        $this->lock->release( $owner );
        $this->reschedule( $runId, $payload );
    }

    private function complete( int $runId, string $owner, int $processed, int $updated, array $counters ) : void {
        $this->update_progress( $runId, [
            'status'          => 'completed',
            'processed_count' => $processed,
            'updated_count'   => $updated,
        ] );
        $summary = [
            'message'   => 'repricer completed',
            'processed' => $processed,
            'updated'   => $updated,
            'counters'  => $counters,
        ];
        $this->runs->mark_success( $runId, $summary );
        $this->logger->info( 'repricer', 'repricer complete', [ 'run_id' => $runId ] + $summary );
        $this->lock->release( $owner );
    }

    /** @param array<string,mixed> $config */
    private function decide( int $productId, array $config ) : array {
        $override = get_post_meta( $productId, 'aurora_price_override', true );
        $sku      = get_post_meta( $productId, '_sku', true );
        $priceRaw = get_post_meta( $productId, '_price', true );
        $price    = '' === $priceRaw ? null : (float) $priceRaw;
        $costRaw  = get_post_meta( $productId, '_aurora_cost', true );
        if ( '' === $costRaw ) {
            $costRaw = get_post_meta( $productId, '_cost', true );
        }
        $cost = '' === $costRaw ? null : (float) $costRaw;

        $rule         = 'no_change';
        $reason       = null;
        $new_price    = $price ?? 0.0;
        $marginBefore = null;
        $marginAfter  = null;

        if ( (string) $override === '1' ) {
            $rule   = 'override';
            $reason = 'override flag';
        } elseif ( null === $cost || $cost <= 0 ) {
            $rule   = 'missing_cost';
            $reason = 'no cost';
        } elseif ( null === $price || $price <= 0 ) {
            $rule   = 'invalid';
            $reason = 'price invalid';
        } else {
            $floorPercent = $cost * ( 1 + ( $config['min_margin_percent'] / 100 ) );
            $floorAbs     = $cost + $config['min_margin_abs'];
            $floor        = max( $floorPercent, $floorAbs );
            if ( $price < $floor ) {
                $new_price = $floor;
                $rule      = 'floor_margin';
                $reason    = 'below floor';
            } else {
                $new_price = $price;
            }
            if ( $price > 0 ) {
                $marginBefore = ( $price - $cost ) / $price;
            }
            if ( $new_price > 0 ) {
                $marginAfter = ( $new_price - $cost ) / $new_price;
            }
        }

        return [
            'product_id'    => $productId,
            'variation_id'  => null,
            'sku'           => $sku ?: null,
            'currency'      => 'EUR',
            'old_price'     => $price,
            'new_price'     => $new_price,
            'cost'          => $cost,
            'margin_before' => $marginBefore,
            'margin_after'  => $marginAfter,
            'rule_applied'  => $rule,
            'reason'        => $reason,
            'applied'       => 0,
        ];
    }

    private function insert_decision( array $data ) : bool {
        $table = $this->db->prefix . 'aurora_reprice_decisions';
        $inserted = $this->db->insert(
            $table,
            [
                'run_id'        => $data['run_id'],
                'product_id'    => $data['product_id'],
                'variation_id'  => $data['variation_id'] ?? null,
                'sku'           => $data['sku'],
                'currency'      => $data['currency'],
                'old_price'     => $data['old_price'],
                'new_price'     => $data['new_price'],
                'cost'          => $data['cost'],
                'margin_before' => $data['margin_before'],
                'margin_after'  => $data['margin_after'],
                'rule_applied'  => $data['rule_applied'],
                'reason'        => $data['reason'],
                'applied'       => $data['applied'],
                'created_at'    => $data['created_at'],
            ],
            [
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%f',
                '%f',
                '%f',
                '%f',
                '%f',
                '%s',
                '%s',
                '%d',
                '%s',
            ]
        );
        return false !== $inserted;
    }

    private function memory_guard_triggered( float $ratio ) : bool {
        if ( $ratio <= 0 ) {
            return false;
        }
        $limitRaw = ini_get( 'memory_limit' );
        if ( false === $limitRaw || '' === $limitRaw || '-1' === $limitRaw ) {
            return false;
        }
        $limitBytes = wp_convert_hr_to_bytes( $limitRaw );
        if ( $limitBytes <= 0 ) {
            return false;
        }
        $usage = memory_get_usage( true );
        return $usage >= ( $limitBytes * $ratio );
    }

    private function reschedule( int $runId, array $payload ) : void {
        $args = [
            [
                'run_id'  => $runId,
                'op_key'  => 'repricer_run',
                'payload' => $payload,
            ],
        ];
        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'aurora_ops_dispatch', $args, 'aurora' ) ) {
            return;
        }
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
        }
    }
}
