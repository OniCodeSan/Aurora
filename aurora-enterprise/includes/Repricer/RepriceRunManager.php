<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Support\Logger;
use Aurora\Enterprise\Repricer\RepriceScopeResolver;
use Aurora\Enterprise\Repricer\RepriceAssignmentRepository;
use wpdb;

class RepriceRunManager {
    private Ops_Run_Manager $runs;
    private Logger $logger;
    private RepriceChunkProcessor $chunks;
    private RepriceLockManager $lock;
    private RepriceScopeResolver $resolver;
    private RepriceAssignmentRepository $assignments;
    private wpdb $db;

    public function __construct( ?RepriceChunkProcessor $chunks = null, ?RepriceLockManager $lock = null, ?RepriceScopeResolver $resolver = null, ?RepriceAssignmentRepository $assignments = null ) {
        $this->runs   = Ops_Run_Manager::instance();
        $this->logger = new Logger();
        $this->chunks = $chunks ?? new RepriceChunkProcessor();
        $this->lock   = $lock ?? new RepriceLockManager();
        $this->resolver = $resolver ?? new RepriceScopeResolver();
        $this->assignments = $assignments ?? new RepriceAssignmentRepository();
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
        $this->logger->info( 'repricer', 'repricer start', [ 'run_id' => $runId, 'payload' => $config ] );

        $scope   = $payload['scope'] ?? null;
        $filters = $payload['filters'] ?? [];
        $assignmentId = isset( $payload['assignment_id'] ) ? (int) $payload['assignment_id'] : null;
        if ( ! $assignmentId ) {
            $runRow = $this->runs->find( $runId );
            if ( is_array( $runRow ) && ! empty( $runRow['meta_json'] ) ) {
                $meta = json_decode( (string) $runRow['meta_json'], true );
                if ( is_array( $meta ) && ! empty( $meta['assignment_id'] ) ) {
                    $assignmentId = (int) $meta['assignment_id'];
                }
            }
        }
        if ( $assignmentId ) {
            $assignment = $this->assignments->get( $assignmentId );
            if ( ! $assignment || (int) ( $assignment['is_enabled'] ?? 0 ) !== 1 ) {
                $this->runs->mark_error( $runId, 'Assignment not found or disabled' );
                return;
            }
            $assignmentScope   = is_array( $assignment['scope_json'] ?? null ) ? $assignment['scope_json'] : [];
            if ( empty( $assignmentScope ) ) {
                $rawScope = $this->db->get_var( $this->db->prepare( "SELECT scope_json FROM {$this->db->prefix}aurora_reprice_assignments WHERE id=%d", $assignmentId ) );
                $decoded  = is_string( $rawScope ) ? json_decode( $rawScope, true ) : [];
                if ( is_array( $decoded ) ) {
                    $assignmentScope = $decoded;
                }
            }
            $assignmentRule    = is_array( $assignment['rule_json'] ?? null ) ? $assignment['rule_json'] : [];
            $assignmentFilters = is_array( $assignment['filters_json'] ?? null ) ? $assignment['filters_json'] : [];
            if ( empty( $assignmentFilters ) ) {
                $rawFilters = $this->db->get_var( $this->db->prepare( "SELECT filters_json FROM {$this->db->prefix}aurora_reprice_assignments WHERE id=%d", $assignmentId ) );
                $decodedFilters = is_string( $rawFilters ) ? json_decode( $rawFilters, true ) : [];
                if ( is_array( $decodedFilters ) ) {
                    $assignmentFilters = $decodedFilters;
                }
            }

            $scope   = $assignmentScope;
            $filters = $assignmentFilters;
            $config  = array_merge( $assignmentRule, $config );
            $config['assignment_id'] = $assignmentId;
            $scopeType = (string) ( $scope['scope_type'] ?? ( $scope['type'] ?? '' ) );
            if ( '' === trim( $scopeType ) ) {
                $this->runs->mark_error( $runId, 'Invalid scope_type' );
                return;
            }
            $this->logger->info(
                'repricer',
                'assignment resolved',
                [
                    'run_id'                => $runId,
                    'assignment_id'         => $assignmentId,
                    'scope_type'            => $scopeType,
                    'products_count'        => is_array( $scope['products'] ?? null ) ? count( $scope['products'] ) : 0,
                    'exclude_products_count'=> is_array( $scope['exclude_products'] ?? null ) ? count( $scope['exclude_products'] ) : 0,
                    'filters_exclude_count' => is_array( $filters['exclude_products'] ?? null ) ? count( $filters['exclude_products'] ) : 0,
                    'categories_count'      => is_array( $scope['categories'] ?? null ) ? count( $scope['categories'] ) : 0,
                ]
            );
        }

        if ( ! $this->lock->acquire( $owner ) ) {
            $this->handle_lock_busy( $runId, $payload );
            return;
        }

        $current = $this->runs->find( $runId );
        $status  = (string) ( $current['status'] ?? '' );
        if ( 'running' !== $status ) {
            if ( ! $this->runs->mark_running( $runId ) ) {
                $this->lock->release( $owner );
                return;
            }
        }

        $progress = $this->load_or_create_progress( $runId );
        $counters = [
            'override'     => 0,
            'missing_cost' => 0,
            'invalid'      => 0,
            'no_change'    => 0,
            'floor_margin' => 0,
        ];
        $decisionsWritten = 0;
        $selected = 0;
        $appliedCount = 0;

        if ( ! is_array( $scope ) || empty( $scope ) ) {
            $this->update_progress( $runId, [
                'status'            => 'failed',
                'processed_count'   => 0,
                'updated_count'     => 0,
                'selected_count'    => 0,
                'decisions_written' => 0,
            ] );
            $debugMsg = sprintf(
                'No products selected (stage=start, scope_products=%d [%s], categories=%d, excludes=%d)',
                is_array( $scope['products'] ?? null ) ? count( $scope['products'] ) : 0,
                is_array( $scope['products'] ?? null ) ? implode( ',', $scope['products'] ) : '',
                is_array( $scope['categories'] ?? null ) ? count( $scope['categories'] ) : 0,
                is_array( $scope['exclude_products'] ?? null ) ? count( $scope['exclude_products'] ) : 0
            );
            $this->runs->mark_error( $runId, $debugMsg );
            $this->lock->release( $owner );
            return;
        }

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
                $this->complete( $runId, $owner, $processed, $updated, $counters, $selected, $decisionsWritten, $startedAt, $config );
                return;
            }

            $limit = min( $chunkSize, $remaining );
            $ids   = $this->resolver->resolve_product_ids( $scope, $filters, $limit, $lastId );
            $selectedCount = count( $ids );

            if ( $selectedCount === 0 ) {
                $this->update_progress( $runId, [
                    'status'            => 'failed',
                    'processed_count'   => $processed,
                    'updated_count'     => $updated,
                    'selected_count'    => 0,
                    'decisions_written' => $decisionsWritten,
                ] );
                $debugMsg = sprintf(
                    'No products selected (stage=loop, scope_products=%d [%s], categories=%d, excludes=%d)',
                    is_array( $scope['products'] ?? null ) ? count( $scope['products'] ) : 0,
                    is_array( $scope['products'] ?? null ) ? implode( ',', $scope['products'] ) : '',
                    is_array( $scope['categories'] ?? null ) ? count( $scope['categories'] ) : 0,
                    is_array( $scope['exclude_products'] ?? null ) ? count( $scope['exclude_products'] ) : 0
                );
                $this->runs->mark_error( $runId, $debugMsg );
                $this->logger->error( 'repricer', 'no products selected', [ 'run_id' => $runId, 'message' => $debugMsg ] );
                $this->lock->release( $owner );
                return;
            }

            $selected += $selectedCount;

            foreach ( $ids as $productId ) {
                $decision = $this->decide( $productId, $config );
                $decision['run_id']     = $runId;
                $decision['created_at'] = current_time( 'mysql', true );
                $applyResult = $this->maybe_apply( $productId, $decision, $config );
                if ( $applyResult['error'] ) {
                    $this->fail_run( $runId, $owner, $applyResult['error'], $counters, $processed, $updated, $selected, $decisionsWritten, microtime( true ) - $startedAt );
                    return;
                }
                $decision = $applyResult['decision'];
                if ( $this->insert_decision( $decision ) ) {
                    $decisionsWritten++;
                    $processed++;
                    $counters[ $decision['rule_applied'] ] = ( $counters[ $decision['rule_applied'] ] ?? 0 ) + 1;
                    if ( (int) $decision['applied'] === 1 ) {
                        $updated++;
                        $appliedCount++;
                    }
                }
                $lastId = $productId;
            }

            $this->update_progress( $runId, [
                'status'          => 'running',
                'last_product_id' => $lastId,
                'processed_count' => $processed,
                'updated_count'   => $updated,
                'selected_count'  => $selected,
                'decisions_written' => $decisionsWritten,
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
            'mode'               => isset( $payload['mode'] ) ? (string) $payload['mode'] : ( ( isset( $payload['dry_run'] ) && false === (bool) $payload['dry_run'] ) ? 'apply' : 'dry_run' ),
            'assignment_id'      => isset( $payload['assignment_id'] ) ? (int) $payload['assignment_id'] : null,
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

    private function complete( int $runId, string $owner, int $processed, int $updated, array $counters, int $selected, int $decisionsWritten, float $startedAt, array $config ) : void {
        $duration = microtime( true ) - $startedAt;
        if ( $selected <= 0 ) {
            $this->fail_run( $runId, $owner, 'No products selected', $counters, $processed, $updated, $selected, $decisionsWritten, $duration );
            return;
        }
        if ( $decisionsWritten <= 0 ) {
            $lastError = $this->db->last_error;
            $this->fail_run( $runId, $owner, 'No decisions written', $counters, $processed, $updated, $selected, $decisionsWritten, $duration, $lastError );
            return;
        }
        $this->update_progress( $runId, [
            'status'          => 'completed',
            'processed_count' => $processed,
            'updated_count'   => $updated,
            'selected_count'  => $selected,
            'decisions_written' => $decisionsWritten,
        ] );
        $summary = [
            'message'   => $updated > 0 ? 'repricer completed' : 'repricer completed (no changes)',
            'processed' => $processed,
            'updated'   => $updated,
            'selected'  => $selected,
            'decisions' => $decisionsWritten,
            'duration'  => $duration,
            'peak_mem'  => memory_get_peak_usage( true ),
            'counters'  => $counters,
            'mode'      => $config['mode'] ?? 'dry_run',
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
            'applied_at_utc'=> null,
            'old_price_applied_from' => null,
            'new_price_applied_to'   => null,
            'rollback_status'        => null,
            'rolled_back_at_utc'     => null,
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
                'applied_at_utc'=> $data['applied_at_utc'],
                'old_price_applied_from' => $data['old_price_applied_from'],
                'new_price_applied_to'   => $data['new_price_applied_to'],
                'rollback_status'        => $data['rollback_status'],
                'rolled_back_at_utc'     => $data['rolled_back_at_utc'],
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
                '%f',
                '%f',
                '%s',
                '%s',
                '%s',
            ]
        );
        return false !== $inserted;
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $config
     * @return array{decision:array<string,mixed>, error:?string}
     */
    private function maybe_apply( int $productId, array $decision, array $config ) : array {
        $mode = (string) ( $config['mode'] ?? 'dry_run' );
        $shouldApply = 'apply' === $mode
            && in_array( $decision['rule_applied'], [ 'floor_margin' ], true )
            && $decision['new_price'] !== null
            && $decision['old_price'] !== null
            && (float) $decision['new_price'] !== (float) $decision['old_price'];

        if ( ! $shouldApply ) {
            return [ 'decision' => $decision, 'error' => null ];
        }

        $old = (float) $decision['old_price'];
        $new = (float) $decision['new_price'];

        $ok1 = update_post_meta( $productId, '_price', $new );
        $ok2 = update_post_meta( $productId, '_regular_price', $new );
        if ( false === $ok1 || false === $ok2 ) {
            return [ 'decision' => $decision, 'error' => 'Failed applying price' ];
        }

        $decision['applied'] = 1;
        $decision['applied_at_utc'] = gmdate( 'Y-m-d H:i:s' );
        $decision['old_price_applied_from'] = $old;
        $decision['new_price_applied_to']   = $new;

        return [ 'decision' => $decision, 'error' => null ];
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

    private function fail_run( int $runId, string $owner, string $message, array $counters, int $processed, int $updated, int $selected, int $decisions, float $duration, string $lastError = '' ) : void {
        $summary = [
            'message'   => $message,
            'processed' => $processed,
            'updated'   => $updated,
            'selected'  => $selected,
            'decisions' => $decisions,
            'duration'  => $duration,
            'last_error'=> $lastError,
            'counters'  => $counters,
        ];
        $this->update_progress( $runId, [
            'status'          => 'failed',
            'processed_count' => $processed,
            'updated_count'   => $updated,
        ] );
        $this->runs->mark_error( $runId, $message );
        $this->logger->error( 'repricer', 'repricer failed', [ 'run_id' => $runId ] + $summary );
        $this->lock->release( $owner );
    }
}
