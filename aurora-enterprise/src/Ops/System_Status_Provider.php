<?php
namespace Aurora\Enterprise\Ops;

use Aurora\Enterprise\Repricer\RepriceAssignmentRepository;

class System_Status_Provider {
    private Ops_Run_Manager $runs;
    private const CACHE_KEY = 'aurora_system_status_v1';
    private const CACHE_TTL = 30;

    public function __construct() {
        $this->runs = Ops_Run_Manager::instance();
    }

    public function get_status() : array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $queues = $this->queue_stats();
        $snap   = $this->snapshot_status();
        $feed   = $this->last_feed();
        $runs   = $this->runs->recent_runs( 20 );
        $repricer = $this->repricer_status();

        [ $healthStatus, $reasons ] = $this->health_rules( $queues, $snap );

        $data = [
            'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'health'   => [
                'status'  => $healthStatus,
                'reasons' => $reasons,
            ],
            'config'   => [
                'queue_driver'    => defined( 'AURORA_QUEUE_DRIVER' ) ? AURORA_QUEUE_DRIVER : 'database',
                'total_shards'    => (int) get_option( 'aurora_total_shards', 8 ),
                'lease_ttl'       => (int) get_option( 'aurora_queue_lease_ttl', 60 ),
                'idempotence_ttl' => (int) get_option( 'aurora_idempotence_ttl', 900 ),
                'snapshot_v2'     => (bool) get_option( 'aurora_snapshot_v2_enabled', false ),
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ],
            'queues'   => $queues,
            'snapshots'=> $snap,
            'feed'     => $feed,
            'last_runs'=> $runs,
            'repricer' => $repricer,
        ];

        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }

    private function queue_stats() : array {
        $stats = \Aurora\Enterprise\Queue\Queue_Manager::instance()->stats();
        return [
            'price'      => (int) ( $stats['price'] ?? 0 ),
            'stock'      => (int) ( $stats['stock'] ?? 0 ),
            'visibility' => (int) ( $stats['visibility'] ?? 0 ),
            'feed'       => (int) ( $stats['feed'] ?? 0 ),
            'dead'       => (int) ( $stats['dead'] ?? 0 ),
        ];
    }

    private function snapshot_status() : array {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_snapshot_versions';
        $versions = [];
        $rows = $wpdb->get_results( "SELECT channel, current_version FROM {$table}", ARRAY_A );
        foreach ( $rows as $row ) {
            $versions[ $row['channel'] ] = (int) $row['current_version'];
        }
        $aligned = count( array_unique( $versions ) ) <= 1;

        $coverage = [];
        foreach ( $versions as $channel => $version ) {
            $snapTable = $wpdb->prefix . "aurora_{$channel}_snapshot";
            $cov = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS c, COUNT(DISTINCT product_id) AS d FROM {$snapTable} WHERE version = %d",
                    $version
                ),
                ARRAY_A
            );
            $coverage[ $channel ] = [
                'version'  => $version,
                'count'    => (int) ( $cov['c'] ?? 0 ),
                'distinct' => (int) ( $cov['d'] ?? 0 ),
            ];
        }

        return [
            'versions'         => $versions,
            'current_versions' => $versions,
            'aligned'          => $aligned,
            'coverage'         => $coverage,
        ];
    }

    private function last_feed() : array {
        $meta = get_option( 'aurora_last_feed_meta', [] );
        return is_array( $meta ) ? $meta : [];
    }

    private function health_rules( array $queues, array $snap ) : array {
        $reasons = [];
        $dead = (int) ( $queues['dead'] ?? 0 );
        if ( $dead > 0 ) {
            $reasons[] = 'dead=' . $dead;
        }

        foreach ( [ 'price', 'stock', 'visibility' ] as $ch ) {
            $pending = (int) ( $queues[ $ch ] ?? 0 );
            if ( $pending > 10000 ) {
                $reasons[] = "backlog {$ch}={$pending}";
            }
        }

        if ( ! ( $snap['aligned'] ?? true ) ) {
            $reasons[] = 'snapshot_versions_misaligned';
        }

        $status = 'HEALTHY';
        if ( ! empty( $reasons ) ) {
            $status = 'WARNING';
        }
        if ( $dead > 0 ) {
            $status = 'ERROR';
        }

        return [ $status, $reasons ];
    }

    private function repricer_status() : array {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            'runs'      => $prefix . 'aurora_ops_runs',
            'progress'  => $prefix . 'aurora_reprice_progress',
            'decisions' => $prefix . 'aurora_reprice_decisions',
        ];

        foreach ( $tables as $key => $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( ! $exists ) {
                return [
                    'tables_missing'    => true,
                    'missing_table_key' => $key,
                    'last_run'          => null,
                    'progress'          => null,
                    'decisions'         => [
                        'decisions_count'  => 0,
                        'distinct_products'=> 0,
                        'breakdown'        => [],
                    ],
                    'recent_decisions'  => [],
                ];
            }
        }

        $assignRepo = new \Aurora\Enterprise\Repricer\RepriceAssignmentRepository();
        $enabledAssignmentsCount = $assignRepo->count_enabled();

        $lastRun = $wpdb->get_row(
            "SELECT id, op_key, status, message, error, requested_at, started_at, finished_at, updated_at, meta_json
             FROM {$tables['runs']}
             WHERE op_key = 'repricer_run'
             ORDER BY id DESC
             LIMIT 1",
            ARRAY_A
        );

        $progress = null;
        if ( $lastRun ) {
            $progress = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT run_id, status, processed_count, updated_count, last_product_id, started_at, updated_at
                     FROM {$tables['progress']}
                     WHERE run_id = %d
                     LIMIT 1",
                    (int) $lastRun['id']
                ),
                ARRAY_A
            );
        }

        $decisionsCount = 0;
        $distinctProducts = 0;
        $breakdown = [];
        $recent = [];
        $appliedCountLastRun = 0;

        if ( $lastRun ) {
            $runId = (int) $lastRun['id'];
            $decisionsCount = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['decisions']} WHERE run_id = %d",
                    $runId
                )
            );
            $distinctProducts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT product_id) FROM {$tables['decisions']} WHERE run_id = %d",
                    $runId
                )
            );
            $breakdown = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_applied, COUNT(*) AS c
                     FROM {$tables['decisions']}
                     WHERE run_id = %d
                     GROUP BY rule_applied
                     ORDER BY c DESC
                     LIMIT 10",
                    $runId
                ),
                ARRAY_A
            ) ?: [];

            $recent = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, variation_id, old_price, new_price, rule_applied, created_at
                     FROM {$tables['decisions']}
                     WHERE run_id = %d
                     ORDER BY id DESC
                     LIMIT 5",
                    $runId
                ),
                ARRAY_A
            ) ?: [];

            $appliedCountLastRun = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['decisions']} WHERE run_id = %d AND applied = 1",
                    $runId
                )
            );
        }

        $lastApplyRun = $wpdb->get_row(
            "SELECT run_id, COUNT(*) as applied_count
             FROM {$tables['decisions']}
             WHERE applied = 1
             GROUP BY run_id
             ORDER BY run_id DESC
             LIMIT 1",
            ARRAY_A
        );
        $lastApplyRun = is_array( $lastApplyRun ) ? $lastApplyRun : null;
        $rollbackPending = 0;
        if ( $lastApplyRun ) {
            $rollbackPending = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['decisions']}
                     WHERE run_id = %d AND applied = 1 AND (rollback_status IS NULL OR rollback_status != 'rolled_back')",
                    (int) $lastApplyRun['run_id']
                )
            );
        }

        $lastRollbackRun = $wpdb->get_row(
            "SELECT id, status, message, error, requested_at, started_at, finished_at
             FROM {$tables['runs']}
             WHERE op_key='repricer_rollback'
             ORDER BY id DESC
             LIMIT 1",
            ARRAY_A
        );

        $rollbackQueue = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tables['runs']} WHERE op_key='repricer_rollback' AND status IN ('requested','running','partial')"
        );

        $mode = 'dry_run';
        if ( $lastRun && ! empty( $lastRun['meta_json'] ) ) {
            $meta = json_decode( (string) $lastRun['meta_json'], true );
            if ( is_array( $meta ) && isset( $meta['mode'] ) ) {
                $mode = (string) $meta['mode'];
            }
        }
        if ( $lastRun ) {
            $lastRun['mode'] = $mode;
            unset( $lastRun['meta_json'] );
        }

        $tickLast = get_option( 'aurora_repricer_tick_last', [] );
        $tickCursor = get_option( 'aurora_repricer_tick_cursor', 0 );
        $scheduler = [
            'enabled'      => true,
            'cursor'       => (int) $tickCursor,
            'enqueued_last'=> is_array( $tickLast ) ? (int) ( $tickLast['enqueued'] ?? 0 ) : 0,
            'skipped_last' => is_array( $tickLast ) ? (int) ( $tickLast['skipped'] ?? 0 ) : 0,
            'last_at'      => is_array( $tickLast ) ? ( $tickLast['at'] ?? null ) : null,
        ];

        $queuedRuns = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tables['runs']} WHERE op_key='repricer_run' AND status IN ('requested','running','partial')"
        );

        $assignmentsPreview = [];
        $assignmentsList = $assignRepo->list_enabled_ordered( 10 );
        foreach ( $assignmentsList as $assignment ) {
            $aid = (int) $assignment['id'];
            $scopeType = $assignment['scope_type'] ?? ( $assignment['scope_json']['scope_type'] ?? ( $assignment['scope_json']['type'] ?? '' ) );
            $lastRunRow = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status, updated_at FROM {$tables['runs']} WHERE op_key='repricer_run' AND meta_json LIKE %s ORDER BY id DESC LIMIT 1",
                    '%"assignment_id":' . $aid . '%'
                ),
                ARRAY_A
            );
            $lastRunSummary = null;
            if ( $lastRunRow ) {
                $rid = (int) $lastRunRow['id'];
                $lastRunSummary = [
                    'id'         => $rid,
                    'status'     => $lastRunRow['status'],
                    'updated_at' => $lastRunRow['updated_at'],
                    'decisions_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['decisions']} WHERE run_id=%d", $rid ) ),
                    'applied_count'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['decisions']} WHERE run_id=%d AND applied=1", $rid ) ),
                ];
            }
            $assignmentsPreview[] = [
                'id'           => $aid,
                'name'         => $assignment['name'] ?? '',
                'priority'     => (int) ( $assignment['priority'] ?? 0 ),
                'is_enabled'   => (int) ( $assignment['is_enabled'] ?? 0 ),
                'scope_type'   => $scopeType,
                'last_run'     => $lastRunSummary,
            ];
        }

        return [
            'tables_missing'    => false,
            'last_run'          => $lastRun ?: null,
            'progress'          => $progress,
            'decisions'         => [
                'decisions_count'   => $decisionsCount,
                'distinct_products' => $distinctProducts,
                'breakdown'         => $breakdown,
                'applied_count_last_run' => $appliedCountLastRun,
            ],
            'recent_decisions'  => $recent,
            'assignments_count' => $enabledAssignmentsCount,
            'enabled_assignments_count' => $enabledAssignmentsCount,
            'queued_runs_count' => $queuedRuns,
            'rollback_queue_count' => $rollbackQueue,
            'assignments'       => $assignmentsPreview,
            'last_assignment'   => $assignRepo->last_assignment(),
            'last_apply_run'    => $lastApplyRun,
            'rollback_pending_count_last_apply_run' => $rollbackPending,
            'last_rollback_run' => $lastRollbackRun ?: null,
            'scheduler'         => $scheduler,
        ];
    }
}
