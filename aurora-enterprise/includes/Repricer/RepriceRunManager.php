<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Support\Logger;
use Aurora\Enterprise\Repricer\RepriceScopeResolver;
use Aurora\Enterprise\Repricer\RepriceAssignmentRepository;
use Aurora\Enterprise\Repricer\RepriceRuleRepository;
use Aurora\Enterprise\Repricer\RepriceRuleEngine;
use wpdb;

class RepriceRunManager {
    private Ops_Run_Manager $runs;
    private Logger $logger;
    private RepriceChunkProcessor $chunks;
    private RepriceLockManager $lock;
    private RepriceScopeResolver $resolver;
    private RepriceAssignmentRepository $assignments;
    private RepriceRuleRepository $rules;
    private RepriceRuleEngine $ruleEngine;
    private RepricePriceEngine $priceEngine;
    private wpdb $db;
    /** @var array<string,bool>|null */
    private ?array $decisionColumns = null;

    public function __construct( ?RepriceChunkProcessor $chunks = null, ?RepriceLockManager $lock = null, ?RepriceScopeResolver $resolver = null, ?RepriceAssignmentRepository $assignments = null ) {
        $this->runs   = Ops_Run_Manager::instance();
        $this->logger = new Logger();
        $this->chunks = $chunks ?? new RepriceChunkProcessor();
        $this->lock   = $lock ?? new RepriceLockManager();
        $this->resolver = $resolver ?? new RepriceScopeResolver();
        $this->assignments = $assignments ?? new RepriceAssignmentRepository();
        $this->rules = new RepriceRuleRepository();
        $this->ruleEngine = new RepriceRuleEngine();
        $this->priceEngine = new RepricePriceEngine();
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
            $selectedRule      = $this->select_priority_rule( $assignmentRule );
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
            $config  = array_merge( $config, $assignmentRule );
            if ( is_array( $selectedRule ) ) {
                $config = array_merge( $config, $selectedRule );
                $config['strategy_rule_id'] = (string) ( $selectedRule['rule_id'] ?? '' );
            }
            $config = $this->apply_payload_overrides( $config, $payload );
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
        $activeRules = $this->rules->list_enabled_ordered( 500 );
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
                if ( $processed > 0 || $selected > 0 || $decisionsWritten > 0 ) {
                    $this->complete( $runId, $owner, $processed, $updated, $counters, $selected, $decisionsWritten, $startedAt, $config );
                    return;
                }
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
                $decision = $this->decide( $productId, $config, $activeRules );
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
        $config = [
            'max_products'       => isset( $payload['max_products'] ) ? max( 1, (int) $payload['max_products'] ) : 10000,
            'chunk_size'         => isset( $payload['chunk_size'] ) ? max( 1, (int) $payload['chunk_size'] ) : ( $optChunk > 0 ? $optChunk : 500 ),
            'min_margin_percent' => isset( $payload['min_margin_percent'] ) ? (float) $payload['min_margin_percent'] : 10.0,
            'min_margin_abs'     => isset( $payload['min_margin_abs'] ) ? (float) $payload['min_margin_abs'] : 1.0,
            'timebox_seconds'    => isset( $payload['timebox_seconds'] ) ? max( 1, (int) $payload['timebox_seconds'] ) : 90,
            'memory_guard_ratio' => isset( $payload['memory_guard_ratio'] ) ? (float) $payload['memory_guard_ratio'] : 0.70,
            'mode'               => isset( $payload['mode'] ) ? (string) $payload['mode'] : ( ( isset( $payload['dry_run'] ) && false === (bool) $payload['dry_run'] ) ? 'apply' : 'dry_run' ),
            'assignment_id'      => isset( $payload['assignment_id'] ) ? (int) $payload['assignment_id'] : null,
            'strategy'           => isset( $payload['strategy'] ) ? sanitize_text_field( (string) $payload['strategy'] ) : 'maintain_margin',
            'margin_mode'        => isset( $payload['margin_mode'] ) ? sanitize_text_field( (string) $payload['margin_mode'] ) : 'clamp',
            'rounding_mode'      => isset( $payload['rounding_mode'] ) ? sanitize_text_field( (string) $payload['rounding_mode'] ) : 'none',
            'rounding_step'      => isset( $payload['rounding_step'] ) ? max( 0.0, (float) $payload['rounding_step'] ) : 0.0,
            'max_raise_pct'      => isset( $payload['max_raise_pct'] ) ? max( 0.0, (float) $payload['max_raise_pct'] ) : 0.0,
            'max_drop_pct'       => isset( $payload['max_drop_pct'] ) ? max( 0.0, (float) $payload['max_drop_pct'] ) : 0.0,
            'hard_max_raise_pct' => isset( $payload['hard_max_raise_pct'] ) ? max( 0.0, (float) $payload['hard_max_raise_pct'] ) : 0.0,
            'hard_max_drop_pct'  => isset( $payload['hard_max_drop_pct'] ) ? max( 0.0, (float) $payload['hard_max_drop_pct'] ) : 0.0,
            'beat_delta_abs'     => isset( $payload['beat_delta_abs'] ) ? max( 0.0, (float) $payload['beat_delta_abs'] ) : 0.0,
            'beat_delta_pct'     => isset( $payload['beat_delta_pct'] ) ? max( 0.0, (float) $payload['beat_delta_pct'] ) : 0.0,
            'target_margin_percent' => isset( $payload['target_margin_percent'] ) ? (float) $payload['target_margin_percent'] : null,
            'target_margin_abs'     => isset( $payload['target_margin_abs'] ) ? (float) $payload['target_margin_abs'] : null,
            'competitor_price'   => isset( $payload['competitor_price'] ) ? (float) $payload['competitor_price'] : null,
            'min_price'          => isset( $payload['min_price'] ) ? (float) $payload['min_price'] : null,
            'max_price'          => isset( $payload['max_price'] ) ? (float) $payload['max_price'] : null,
            'map_price'          => isset( $payload['map_price'] ) ? (float) $payload['map_price'] : null,
        ];
        return $this->apply_payload_overrides( $config, $payload );
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function apply_payload_overrides( array $config, array $payload ) : array {
        $numericKeys = [
            'max_products', 'chunk_size', 'timebox_seconds', 'min_margin_percent', 'min_margin_abs',
            'memory_guard_ratio', 'rounding_step', 'max_raise_pct', 'max_drop_pct', 'hard_max_raise_pct', 'hard_max_drop_pct', 'beat_delta_abs',
            'beat_delta_pct', 'target_margin_percent', 'target_margin_abs', 'competitor_price',
            'min_price', 'max_price', 'map_price',
        ];
        foreach ( $numericKeys as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $config[ $key ] = $payload[ $key ];
            }
        }
        $stringKeys = [ 'strategy', 'margin_mode', 'rounding_mode', 'mode', 'strategy_rule_id' ];
        foreach ( $stringKeys as $key ) {
            if ( array_key_exists( $key, $payload ) ) {
                $config[ $key ] = sanitize_text_field( (string) $payload[ $key ] );
            }
        }
        if ( array_key_exists( 'dry_run', $payload ) && ! array_key_exists( 'mode', $payload ) ) {
            $config['mode'] = (bool) $payload['dry_run'] ? 'dry_run' : 'apply';
        }
        return $config;
    }

    /**
     * @param array<string,mixed> $ruleJson
     * @return array<string,mixed>|null
     */
    private function select_priority_rule( array $ruleJson ) : ?array {
        $rules = $ruleJson['rules'] ?? null;
        if ( ! is_array( $rules ) || empty( $rules ) ) {
            return null;
        }
        $normalized = [];
        foreach ( $rules as $index => $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            if ( isset( $rule['is_enabled'] ) && ! (bool) $rule['is_enabled'] ) {
                continue;
            }
            if ( isset( $rule['enabled'] ) && ! (bool) $rule['enabled'] ) {
                continue;
            }
            $entry = $rule;
            $entry['priority'] = isset( $rule['priority'] ) ? (int) $rule['priority'] : 0;
            $entry['rule_id'] = isset( $rule['rule_id'] ) ? (string) $rule['rule_id'] : ( isset( $rule['id'] ) ? (string) $rule['id'] : sprintf( 'rule_%04d', (int) $index ) );
            $normalized[] = $entry;
        }
        if ( empty( $normalized ) ) {
            return null;
        }
        usort(
            $normalized,
            static function ( array $a, array $b ) : int {
                $priorityCmp = (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
                if ( 0 !== $priorityCmp ) {
                    return $priorityCmp;
                }
                return strcmp( (string) ( $a['rule_id'] ?? '' ), (string) ( $b['rule_id'] ?? '' ) );
            }
        );
        return $normalized[0];
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

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $activeRules
     */
    private function decide( int $productId, array $config, array $activeRules = [] ) : array {
        $allMeta = get_post_meta( $productId );
        $metaValue = static function ( array $meta, string $key ) {
            if ( ! isset( $meta[ $key ] ) || ! is_array( $meta[ $key ] ) || ! isset( $meta[ $key ][0] ) ) {
                return '';
            }
            return $meta[ $key ][0];
        };

        $override = (string) $metaValue( $allMeta, 'aurora_price_override' );
        $sku = (string) $metaValue( $allMeta, '_sku' );
        $priceRaw = $metaValue( $allMeta, '_price' );
        $price = '' === $priceRaw ? null : (float) $priceRaw;
        $costRaw = $metaValue( $allMeta, '_aurora_cost' );
        if ( '' === $costRaw ) {
            $costRaw = $metaValue( $allMeta, '_cost' );
        }
        $cost = '' === $costRaw ? null : (float) $costRaw;
        $competitorRaw = $metaValue( $allMeta, '_aurora_competitor_price' );
        $competitor = '' === $competitorRaw ? null : (float) $competitorRaw;
        $minPriceRaw = $metaValue( $allMeta, '_aurora_min_price' );
        $maxPriceRaw = $metaValue( $allMeta, '_aurora_max_price' );
        $mapRaw = $metaValue( $allMeta, '_aurora_map_price' );
        if ( '' === $mapRaw ) {
            $mapRaw = $metaValue( $allMeta, '_map_price' );
        }
        $minPrice = '' === $minPriceRaw ? null : (float) $minPriceRaw;
        $maxPrice = '' === $maxPriceRaw ? null : (float) $maxPriceRaw;
        $mapPrice = '' === $mapRaw ? null : (float) $mapRaw;

        $engineResult = null;
        if ( ! empty( $activeRules ) ) {
            $brandTax = taxonomy_exists( 'product_brand' ) ? 'product_brand' : ( taxonomy_exists( 'pa_brand' ) ? 'pa_brand' : null );
            $categoryIds = wp_get_object_terms( $productId, 'product_cat', [ 'fields' => 'ids' ] );
            $brandIds = $brandTax ? wp_get_object_terms( $productId, $brandTax, [ 'fields' => 'ids' ] ) : [];
            $productTypes = wp_get_object_terms( $productId, 'product_type', [ 'fields' => 'slugs' ] );

            $context = [
                'product_id'          => $productId,
                'old_price'           => $price,
                'cost'                => $cost,
                'override'            => $override,
                'competitor_price'    => $competitor,
                'min_price'           => $minPrice,
                'max_price'           => $maxPrice,
                'map_price'           => $mapPrice,
                'stock_qty'           => $metaValue( $allMeta, '_stock' ),
                'supplier_id'         => (string) $metaValue( $allMeta, '_aurora_supplier_id' ),
                'product_type'        => ! empty( $productTypes ) && is_array( $productTypes ) ? (string) reset( $productTypes ) : (string) $metaValue( $allMeta, '_aurora_product_type' ),
                'line'                => (string) $metaValue( $allMeta, '_aurora_line' ),
                'urgent_only'         => in_array( strtolower( (string) $metaValue( $allMeta, '_aurora_urgent' ) ), [ '1', 'true', 'yes', 'on' ], true ),
                'top_search_only'     => in_array( strtolower( (string) $metaValue( $allMeta, '_aurora_top_search' ) ), [ '1', 'true', 'yes', 'on' ], true ),
                'competitor_position' => (int) $metaValue( $allMeta, '_aurora_competitor_position' ),
                'reviews_count'       => (int) $metaValue( $allMeta, '_wc_review_count' ),
                'rotation_index'      => $metaValue( $allMeta, '_aurora_rotation_index' ),
                'sold_last_30_days'   => $metaValue( $allMeta, '_aurora_sold_last_30_days' ),
                'category_ids'        => is_array( $categoryIds ) ? array_map( 'intval', $categoryIds ) : [],
                'brand_ids'           => is_array( $brandIds ) ? array_map( 'intval', $brandIds ) : [],
            ];
            $engineResult = $this->ruleEngine->evaluate_rules_for_product( $activeRules, $context, $config );
        }

        if ( ! is_array( $engineResult ) ) {
            $engineResult = $this->priceEngine->evaluate(
                [
                    'old_price'         => $price,
                    'cost'              => $cost,
                    'override'          => $override,
                    'competitor_price'  => $competitor,
                    'min_price'         => $minPrice,
                    'max_price'         => $maxPrice,
                    'map_price'         => $mapPrice,
                ],
                $config
            );
        }

        $reasonCodes = $engineResult['reason_codes'] ?? [];
        if ( ! is_array( $reasonCodes ) ) {
            $reasonCodes = [];
        }
        $reasonText = implode( ',', array_values( array_filter( array_map( 'strval', $reasonCodes ) ) ) );
        if ( '' === $reasonText ) {
            $reasonText = (string) ( $engineResult['reason_code'] ?? '' );
        }

        return [
            'product_id'    => $productId,
            'variation_id'  => null,
            'sku'           => $sku ?: null,
            'currency'      => 'EUR',
            'old_price'     => $engineResult['old_price'],
            'candidate_price'=> $engineResult['candidate_price'],
            'clamped_price' => $engineResult['clamped_price'],
            'rounded_price' => $engineResult['rounded_price'],
            'new_price'     => $engineResult['new_price'],
            'delta_pct'     => $engineResult['delta_pct'],
            'cost'          => $engineResult['cost'],
            'competitor_price' => $engineResult['competitor_price'],
            'min_price'     => $engineResult['min_price'],
            'max_price'     => $engineResult['max_price'],
            'map_price'     => $engineResult['map_price'],
            'margin_before' => $engineResult['margin_before'],
            'margin_after'  => $engineResult['margin_after'],
            'rule_applied'  => (string) ( $engineResult['rule_applied'] ?? 'no_change' ),
            'strategy_key'  => (string) ( $engineResult['strategy_key'] ?? 'maintain_margin' ),
            'strategy_rule_id' => isset( $engineResult['strategy_rule_id'] ) ? (string) $engineResult['strategy_rule_id'] : null,
            'reason_code'   => (string) ( $engineResult['reason_code'] ?? 'no_change' ),
            'reason_codes_json' => wp_json_encode( $reasonCodes ),
            'reason'        => ! empty( $engineResult['price_rule_name'] ) ? ( $reasonText . '|rule=' . sanitize_text_field( (string) $engineResult['price_rule_name'] ) ) : $reasonText,
            'audit_json'    => $engineResult['audit_json'] ?? null,
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
        $row = [
            'run_id'        => $data['run_id'],
            'product_id'    => $data['product_id'],
            'variation_id'  => $data['variation_id'] ?? null,
            'sku'           => $data['sku'],
            'currency'      => $data['currency'],
            'old_price'     => $data['old_price'],
            'candidate_price' => $data['candidate_price'] ?? null,
            'clamped_price' => $data['clamped_price'] ?? null,
            'rounded_price' => $data['rounded_price'] ?? null,
            'new_price'     => $data['new_price'],
            'delta_pct'     => $data['delta_pct'] ?? null,
            'cost'          => $data['cost'],
            'competitor_price' => $data['competitor_price'] ?? null,
            'min_price'     => $data['min_price'] ?? null,
            'max_price'     => $data['max_price'] ?? null,
            'map_price'     => $data['map_price'] ?? null,
            'margin_before' => $data['margin_before'],
            'margin_after'  => $data['margin_after'],
            'rule_applied'  => $data['rule_applied'],
            'strategy_key'  => $data['strategy_key'] ?? null,
            'strategy_rule_id' => $data['strategy_rule_id'] ?? null,
            'reason_code'   => $data['reason_code'] ?? null,
            'reason_codes_json' => $data['reason_codes_json'] ?? null,
            'reason'        => $data['reason'],
            'audit_json'    => $data['audit_json'] ?? null,
            'applied'       => $data['applied'],
            'applied_at_utc'=> $data['applied_at_utc'],
            'old_price_applied_from' => $data['old_price_applied_from'],
            'new_price_applied_to'   => $data['new_price_applied_to'],
            'rollback_status'        => $data['rollback_status'],
            'rolled_back_at_utc'     => $data['rolled_back_at_utc'],
            'created_at'    => $data['created_at'],
        ];
        $formatsMap = [
            'run_id' => '%d',
            'product_id' => '%d',
            'variation_id' => '%d',
            'sku' => '%s',
            'currency' => '%s',
            'old_price' => '%f',
            'candidate_price' => '%f',
            'clamped_price' => '%f',
            'rounded_price' => '%f',
            'new_price' => '%f',
            'delta_pct' => '%f',
            'cost' => '%f',
            'competitor_price' => '%f',
            'min_price' => '%f',
            'max_price' => '%f',
            'map_price' => '%f',
            'margin_before' => '%f',
            'margin_after' => '%f',
            'rule_applied' => '%s',
            'strategy_key' => '%s',
            'strategy_rule_id' => '%s',
            'reason_code' => '%s',
            'reason_codes_json' => '%s',
            'reason' => '%s',
            'audit_json' => '%s',
            'applied' => '%d',
            'applied_at_utc' => '%s',
            'old_price_applied_from' => '%f',
            'new_price_applied_to' => '%f',
            'rollback_status' => '%s',
            'rolled_back_at_utc' => '%s',
            'created_at' => '%s',
        ];
        $columns = $this->decision_columns();
        $filteredRow = [];
        $formats = [];
        foreach ( $row as $column => $value ) {
            if ( empty( $columns[ $column ] ) ) {
                continue;
            }
            $filteredRow[ $column ] = $value;
            $formats[] = $formatsMap[ $column ] ?? '%s';
        }
        $inserted = $this->db->insert( $table, $filteredRow, $formats );
        return false !== $inserted;
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $config
     * @return array{decision:array<string,mixed>, error:?string}
     */
    private function maybe_apply( int $productId, array $decision, array $config ) : array {
        $mode = (string) ( $config['mode'] ?? 'dry_run' );
        $blockedReason = (string) ( $decision['reason_code'] ?? '' );
        $shouldApply = 'apply' === $mode
            && ! in_array( $blockedReason, [ 'override_flag', 'missing_cost', 'invalid_price', 'floor_margin_block' ], true )
            && $decision['new_price'] !== null
            && $decision['old_price'] !== null
            && (float) $decision['new_price'] !== (float) $decision['old_price'];

        if ( ! $shouldApply ) {
            return [ 'decision' => $decision, 'error' => null ];
        }

        $old = (float) $decision['old_price'];
        $new = (float) $decision['new_price'];
        $hardGuardError = $this->validate_hard_price_guard( $productId, $old, $new, $config );
        if ( null !== $hardGuardError ) {
            return [ 'decision' => $decision, 'error' => $hardGuardError ];
        }

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

    /**
     * @param array<string,mixed> $config
     */
    private function validate_hard_price_guard( int $productId, float $old, float $new, array $config ) : ?string {
        if ( $old <= 0.0 ) {
            return null;
        }
        $maxRaise = max( 0.0, (float) ( $config['hard_max_raise_pct'] ?? 0.0 ) );
        $maxDrop  = max( 0.0, (float) ( $config['hard_max_drop_pct'] ?? 0.0 ) );
        if ( $maxRaise <= 0.0 && $maxDrop <= 0.0 ) {
            return null;
        }

        $deltaPct = ( ( $new - $old ) / $old ) * 100.0;
        if ( $maxRaise > 0.0 && $deltaPct > $maxRaise ) {
            return sprintf(
                'Hard price guard triggered: increase %.4f%% > %.4f%% (product_id=%d)',
                $deltaPct,
                $maxRaise,
                $productId
            );
        }
        if ( $maxDrop > 0.0 && $deltaPct < ( -1.0 * $maxDrop ) ) {
            return sprintf(
                'Hard price guard triggered: drop %.4f%% > %.4f%% (product_id=%d)',
                abs( $deltaPct ),
                $maxDrop,
                $productId
            );
        }
        return null;
    }

    /**
     * @return array<string,bool>
     */
    private function decision_columns() : array {
        if ( is_array( $this->decisionColumns ) ) {
            return $this->decisionColumns;
        }
        $table = $this->db->prefix . 'aurora_reprice_decisions';
        $rows = $this->db->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $columns = [];
        foreach ( $rows ?: [] as $row ) {
            $name = isset( $row['Field'] ) ? (string) $row['Field'] : '';
            if ( '' !== $name ) {
                $columns[ $name ] = true;
            }
        }
        $this->decisionColumns = $columns;
        return $columns;
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
