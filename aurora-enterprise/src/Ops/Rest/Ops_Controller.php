<?php
namespace Aurora\Enterprise\Ops\Rest;

use Aurora\Enterprise\Ops\System_Status_Provider;
use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Ops\Ops_Dispatcher;
use Aurora\Enterprise\Repricer\RepriceAssignmentRepository;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Ops_Controller {
    private System_Status_Provider $provider;
    private Ops_Run_Manager $runs;

    public function __construct() {
        $this->provider = new System_Status_Provider();
        $this->runs     = Ops_Run_Manager::instance();
    }

    public function register_routes() : void {
        register_rest_route( 'aurora/v1', '/system-status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/ops-ui-status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_ops_ui_status' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/ops-ui-status', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'get_ops_ui_status' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/trigger', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'trigger' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/trigger/rebuild', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'trigger_rebuild' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'indexer' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => [ 'price', 'stock', 'visibility', 'all' ],
                ],
            ],
        ] );

        register_rest_route( 'aurora/v1', '/trigger/sweep-leases', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'trigger_sweep' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'channel' => [
                    'type'     => 'string',
                    'required' => false,
                    'enum'     => [ 'price', 'stock', 'visibility', 'feed', 'all' ],
                ],
                'older_than' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
                'shard' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
                'total_shards' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
            ],
        ] );

        register_rest_route( 'aurora/v1', '/trigger/feed-enqueue', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'trigger_feed_enqueue' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'chunk_size' => [
                    'type'    => 'integer',
                    'default' => 1000,
                ],
            ],
        ] );

        register_rest_route( 'aurora/v1', '/trigger/feed-run', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'trigger_feed_run' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'batch' => [
                    'type'     => 'integer',
                    'required' => false,
                    'default'  => 100,
                ],
                'max_loops' => [
                    'type'     => 'integer',
                    'required' => false,
                    'default'  => 1,
                ],
                'shard' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
                'total_shards' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
            ],
        ] );

        register_rest_route( 'aurora/v1', '/feed/run', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'feed_run' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/run', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_run' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/apply', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_apply' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/run-all', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_run_all' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/preview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_preview' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/scheduler/tick', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_scheduler_tick' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/assignments', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_assignments_create' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/assignments', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'repricer_assignments_list' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/assignments/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'repricer_assignments_get' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );
    }

    public function get_status() : WP_REST_Response {
        return new WP_REST_Response( $this->provider->get_status() );
    }

    public function get_ops_ui_status() : WP_REST_Response {
        global $wpdb;

        $table      = $wpdb->prefix . 'aurora_ops_runs';
        $exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $queueStats = \Aurora\Enterprise\Queue\Queue_Manager::instance()->stats();
        $queue = [
            'price'      => (int) ( $queueStats['price'] ?? 0 ),
            'stock'      => (int) ( $queueStats['stock'] ?? 0 ),
            'visibility' => (int) ( $queueStats['visibility'] ?? 0 ),
            'feed'       => (int) ( $queueStats['feed'] ?? 0 ),
            'dead'       => (int) ( $queueStats['dead'] ?? 0 ),
        ];
        $queue['backlog_total'] = $queue['price'] + $queue['stock'] + $queue['visibility'] + $queue['feed'];
        $queue['oldest_pending_seconds'] = null;
        $queue['retryable_dead'] = 0;
        $queue['active_leases'] = 0;
        $queue['stale_leases'] = 0;

        $queueTable = $wpdb->prefix . 'product_index_queue';
        $queueExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queueTable ) );
        if ( $queueExists ) {
            $oldestPending = $wpdb->get_var(
                "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP())
                 FROM {$queueTable}
                 WHERE status = 'pending'"
            );
            $queue['oldest_pending_seconds'] = null !== $oldestPending ? (int) $oldestPending : null;
            $queue['retryable_dead'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queueTable} WHERE status = 'dead' AND attempts < 5" );
            $queue['active_leases'] = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$queueTable}
                 WHERE status = 'processing'
                   AND lease_expires_at IS NOT NULL
                   AND lease_expires_at > UTC_TIMESTAMP()"
            );
            $queue['stale_leases'] = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$queueTable}
                 WHERE status = 'processing'
                   AND lease_expires_at IS NOT NULL
                   AND lease_expires_at <= UTC_TIMESTAMP()"
            );
        }

        $empty = [
            'ok'                  => true,
            'generated_at_utc'    => gmdate( 'Y-m-d H:i:s' ),
            'health'              => [
                'status'  => 'WARN',
                'reasons' => [ 'ops_runs_table_missing' ],
            ],
            'queue'               => $queue,
            'ops_errors'          => [
                'filtered' => 0,
                'total'    => 0,
            ],
            'incidents'           => [
                'summary' => [
                    'errors_24h'            => 0,
                    'unique_ops_impacted'   => 0,
                    'last_incident_at'      => null,
                    'most_frequent_op'      => null,
                ],
                'items' => [],
            ],
            'action_scheduler'    => [
                'pending'  => 0,
                'past_due' => 0,
            ],
            'config'              => [
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ],
            'diagnostics'         => [
                'recent_ops_errors'        => 0,
                'snapshot_alignment_raw'   => null,
                'download_status_supported'=> true,
            ],
            'last_run_timestamps' => [],
            'last_error'          => null,
            'recent_runs'         => [],
        ];
        if ( ! $exists ) {
            return new WP_REST_Response( $empty );
        }

        $runs = $wpdb->get_results(
            "SELECT id, op_key, status, created_at, started_at, finished_at, message, error
             FROM {$table}
             ORDER BY id DESC
             LIMIT 20",
            ARRAY_A
        ) ?: [];

        $sanitizedRuns = [];
        foreach ( $runs as $row ) {
            $sanitizedRuns[] = [
                'id'         => (int) ( $row['id'] ?? 0 ),
                'op_key'     => sanitize_text_field( (string) ( $row['op_key'] ?? '' ) ),
                'status'     => sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
                'created_at' => $row['created_at'] ?? null,
                'started_at' => $row['started_at'] ?? null,
                'finished_at'=> $row['finished_at'] ?? null,
                'message'    => $this->sanitize_run_message( $row['message'] ?? '' ),
                'error'      => $this->sanitize_run_message( $row['error'] ?? '' ),
            ];
        }

        $errorsTotal = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'error'" );
        $errorsFiltered = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'error'
               AND op_key IN ('repricer_run','feed_enqueue','feed_run','rebuild','sweep_leases')
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );
        $uniqueOpsImpacted = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT op_key)
             FROM {$table}
             WHERE status = 'error'
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );
        $mostFrequentOpRow = $wpdb->get_row(
            "SELECT op_key, COUNT(*) AS c
             FROM {$table}
             WHERE status = 'error'
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             GROUP BY op_key
             ORDER BY c DESC
             LIMIT 1",
            ARRAY_A
        );

        $timestamps = [
            'repricer_tick' => null,
            'repricer_run' => null,
            'feed_enqueue' => null,
            'feed_run'     => null,
            'rebuild'      => null,
            'sweep_leases' => null,
        ];
        $lastTick = get_option( 'aurora_repricer_tick_last', [] );
        if ( is_array( $lastTick ) ) {
            $timestamps['repricer_tick'] = $lastTick['at'] ?? ( $lastTick['last_at'] ?? null );
        }
        $lastByOpRows = $wpdb->get_results(
            "SELECT op_key, MAX(created_at) AS last_created_at
             FROM {$table}
             WHERE op_key IN ('repricer_run','feed_enqueue','feed_run','rebuild','sweep_leases')
             GROUP BY op_key",
            ARRAY_A
        ) ?: [];
        foreach ( $lastByOpRows as $row ) {
            $opKey = (string) ( $row['op_key'] ?? '' );
            if ( array_key_exists( $opKey, $timestamps ) ) {
                $timestamps[ $opKey ] = $row['last_created_at'] ?: null;
            }
        }

        $lastErrorRow = $wpdb->get_row(
            "SELECT id, op_key, error, message, created_at
             FROM {$table}
             WHERE status = 'error'
             ORDER BY id DESC
             LIMIT 1",
            ARRAY_A
        );
        $incidentsRows = $wpdb->get_results(
            "SELECT id, op_key, status, error, message, created_at
             FROM {$table}
             WHERE status = 'error'
             ORDER BY id DESC
             LIMIT 20",
            ARRAY_A
        ) ?: [];

        $incidents = [];
        foreach ( $incidentsRows as $row ) {
            $rawMessage = (string) ( $row['error'] ?: ( $row['message'] ?? '' ) );
            $summary = $this->sanitize_run_message( $rawMessage );
            $impact = 'Questa operazione potrebbe non completarsi correttamente.';
            if ( str_contains( $rawMessage, 'Snapshot mismatch' ) ) {
                $impact = 'Il feed può usare snapshot non allineati.';
            } elseif ( str_contains( $rawMessage, 'No products selected' ) ) {
                $impact = 'Il repricer non ha trovato prodotti eleggibili.';
            } elseif ( str_contains( $rawMessage, 'rate limit' ) ) {
                $impact = 'I trigger possono essere temporaneamente rifiutati.';
            }
            $incidents[] = [
                'id'         => (int) ( $row['id'] ?? 0 ),
                'severity'   => 'ERROR',
                'op_key'     => sanitize_text_field( (string) ( $row['op_key'] ?? '' ) ),
                'summary'    => $summary,
                'impact'     => $impact,
                'created_at' => $row['created_at'] ?? null,
                'raw'        => $rawMessage,
            ];
        }

        $as = [
            'pending'  => 0,
            'past_due' => 0,
        ];
        $asActionsTable  = $wpdb->prefix . 'actionscheduler_actions';
        $asStatusesTable = $wpdb->prefix . 'actionscheduler_statuses';
        $asActionsExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $asActionsTable ) );
        $asStatusesExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $asStatusesTable ) );
        if ( $asActionsExists && $asStatusesExists ) {
            $as['pending'] = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$asActionsTable} a
                 INNER JOIN {$asStatusesTable} s ON a.status_id = s.status_id
                 WHERE s.status = 'pending'"
            );
            $as['past_due'] = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$asActionsTable} a
                 INNER JOIN {$asStatusesTable} s ON a.status_id = s.status_id
                 WHERE s.status = 'pending'
                   AND a.scheduled_date_gmt <= UTC_TIMESTAMP()"
            );
        }

        $healthStatus = 'OK';
        $healthReasons = [];
        if ( $queue['dead'] > 0 ) {
            $healthStatus = 'FAIL';
            $healthReasons[] = 'dead_queue=' . $queue['dead'];
        } elseif ( $as['past_due'] > 20 ) {
            $healthStatus = 'ERROR';
            $healthReasons[] = 'actionscheduler_past_due=' . $as['past_due'];
        } elseif ( $as['past_due'] > 0 ) {
            $healthStatus = 'WARN';
            $healthReasons[] = 'actionscheduler_past_due=' . $as['past_due'];
        } elseif ( $errorsFiltered > 0 ) {
            $healthStatus = 'WARN';
            $healthReasons[] = 'recent_ops_errors=' . $errorsFiltered;
        } elseif ( $errorsTotal > 0 ) {
            $healthStatus = 'WARN';
            $healthReasons[] = 'ops_errors_total=' . $errorsTotal;
        }

        $payload = [
            'ok'                  => true,
            'generated_at_utc'    => gmdate( 'Y-m-d H:i:s' ),
            'health'              => [
                'status'  => $healthStatus,
                'reasons' => $healthReasons,
            ],
            'queue'               => $queue,
            'ops_errors'          => [
                'filtered' => $errorsFiltered,
                'total'    => $errorsTotal,
            ],
            'incidents'           => [
                'summary' => [
                    'errors_24h'            => $errorsFiltered,
                    'unique_ops_impacted'   => $uniqueOpsImpacted,
                    'last_incident_at'      => $incidents[0]['created_at'] ?? null,
                    'most_frequent_op'      => $mostFrequentOpRow ? (string) ( $mostFrequentOpRow['op_key'] ?? '' ) : null,
                ],
                'items' => $incidents,
            ],
            'action_scheduler'    => $as,
            'config'              => [
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ],
            'diagnostics'         => [
                'recent_ops_errors'         => $errorsFiltered,
                'snapshot_alignment_raw'    => ( $lastErrorRow && str_contains( (string) ( $lastErrorRow['error'] ?? '' ), 'Snapshot mismatch' ) )
                    ? (string) ( $lastErrorRow['error'] ?? '' )
                    : null,
                'download_status_supported' => true,
            ],
            'last_run_timestamps' => $timestamps,
            'last_error'          => $lastErrorRow ? [
                'id'         => (int) ( $lastErrorRow['id'] ?? 0 ),
                'op_key'     => sanitize_text_field( (string) ( $lastErrorRow['op_key'] ?? '' ) ),
                'message'    => $this->sanitize_run_message( (string) ( $lastErrorRow['error'] ?: ( $lastErrorRow['message'] ?? '' ) ) ),
                'created_at' => $lastErrorRow['created_at'] ?? null,
            ] : null,
            'recent_runs'         => $sanitizedRuns,
        ];

        return new WP_REST_Response( $payload );
    }

    public function repricer_run( WP_REST_Request $request ) {
        global $wpdb;
        $existing = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}aurora_ops_runs WHERE op_key='repricer_run' AND status IN ('requested','running','partial') ORDER BY id DESC LIMIT 1"
        );
        if ( $existing ) {
            return new WP_Error( 'aurora_ops_repricer_busy', 'Repricer run already in progress', [ 'status' => 409, 'run_id' => (int) $existing ] );
        }

        $dryRun       = $this->bool_param( $request, 'dry_run', true );
        $requestedMode = sanitize_text_field( (string) ( $request->get_param( 'mode' ) ?? '' ) );
        $mode         = in_array( $requestedMode, [ 'dry_run', 'apply' ], true ) ? $requestedMode : ( $dryRun ? 'dry_run' : 'apply' );
        $assignment_id = absint( $request->get_param( 'assignment_id' ) );
        $payload = [
            'max_products'       => $this->int_param( $request, 'max_products', 10000, 1, 200000 ),
            'chunk_size'         => $this->int_param( $request, 'chunk_size', 500, 1, 5000 ),
            'timebox_seconds'    => $this->int_param( $request, 'timebox_seconds', 90, 5, 3600 ),
            'min_margin_percent' => $this->float_param( $request, 'min_margin_percent', 0.0, 0.0, 1000.0 ),
            'min_margin_abs'     => $this->float_param( $request, 'min_margin_abs', 0.0, 0.0, 1000000.0 ),
            'dry_run'            => $dryRun,
            'mode'               => $mode,
        ];
        if ( $assignment_id > 0 ) {
            $payload['assignment_id'] = $assignment_id;
        }

        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'aurora_ops_runs',
            [
                'op_key'       => 'repricer_run',
                'action_type'  => 'repricer_run',
                'status'       => 'requested',
                'requested_at' => $now,
                'started_at'   => null,
                'finished_at'  => null,
                'message'      => null,
                'error'        => null,
                'meta_json'    => wp_json_encode( $payload ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );
        if ( false === $inserted ) {
            return new WP_Error( 'aurora_ops_run_create_failed', 'Unable to create ops run row.', [ 'status' => 500 ] );
        }
        $run_id = (int) $wpdb->insert_id;
        $payload['run_id'] = $run_id;

        $args = [
            [
                'run_id'  => $run_id,
                'op_key'  => 'repricer_run',
                'payload' => $payload,
            ],
        ];

        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'aurora_ops_dispatch', $args, 'aurora' ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'run_id' => $run_id, 'scheduled' => true ], 200 );
        }

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $action_id = as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
            if ( $action_id > 0 ) {
                return new WP_REST_Response( [ 'ok' => true, 'run_id' => $run_id, 'scheduled' => true ], 200 );
            }
        }

        return new WP_Error( 'aurora_ops_schedule_failed', 'Failed to schedule repricer run.', [ 'status' => 500 ] );
    }

    public function repricer_apply( WP_REST_Request $request ) {
        global $wpdb;
        $assignment_id = absint( $request->get_param( 'assignment_id' ) );
        if ( $assignment_id <= 0 ) {
            return new WP_Error( 'aurora_repricer_apply_bad_request', 'assignment_id required', [ 'status' => 400 ] );
        }
        $assignment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}aurora_reprice_assignments WHERE id=%d AND is_enabled=1 LIMIT 1",
                $assignment_id
            ),
            ARRAY_A
        );
        if ( ! $assignment ) {
            return new WP_Error( 'aurora_repricer_apply_not_found', 'Assignment not found or disabled', [ 'status' => 404 ] );
        }

        $dup = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}aurora_ops_runs WHERE op_key='repricer_run' AND status IN ('requested','running','partial') AND meta_json LIKE %s AND meta_json LIKE '%\"mode\":\"apply\"%' LIMIT 1",
                '%"assignment_id":' . $assignment_id . '%'
            )
        );
        if ( $dup ) {
            error_log( sprintf( '[Aurora] repricer_apply dedup assignment_id=%d run_id=%d', $assignment_id, (int) $dup ) );
            return new WP_Error( 'aurora_repricer_apply_duplicate', 'Repricer apply already pending for assignment', [ 'status' => 409, 'run_id' => (int) $dup ] );
        }

        $payload = [
            'assignment_id'      => $assignment_id,
            'mode'               => 'apply',
            'dry_run'            => false,
            'max_products'       => $this->int_param( $request, 'max_products', 10000, 1, 200000 ),
            'chunk_size'         => $this->int_param( $request, 'chunk_size', 500, 1, 5000 ),
            'timebox_seconds'    => $this->int_param( $request, 'timebox_seconds', 90, 5, 3600 ),
            'min_margin_percent' => $this->float_param( $request, 'min_margin_percent', 0.0, 0.0, 1000.0 ),
            'min_margin_abs'     => $this->float_param( $request, 'min_margin_abs', 0.0, 0.0, 1000000.0 ),
        ];

        error_log( sprintf( '[Aurora] repricer_apply request assignment_id=%d', $assignment_id ) );

        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'aurora_ops_runs',
            [
                'op_key'       => 'repricer_run',
                'action_type'  => 'repricer_run',
                'status'       => 'requested',
                'requested_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
                'meta_json'    => wp_json_encode( $payload ),
            ]
        );
        if ( false === $inserted ) {
            return new WP_Error( 'aurora_repricer_apply_insert_failed', 'Unable to create ops run row.', [ 'status' => 500 ] );
        }
        $run_id = (int) $wpdb->insert_id;
        $payload['run_id'] = $run_id;
        $args = [
            [
                'run_id'  => $run_id,
                'op_key'  => 'repricer_run',
                'payload' => $payload,
            ],
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
        }

        error_log( sprintf( '[Aurora] repricer_apply scheduled assignment_id=%d run_id=%d', $assignment_id, $run_id ) );
        return new WP_REST_Response( [ 'ok' => true, 'run_id' => $run_id, 'scheduled' => true ], 200 );
    }

    public function repricer_run_all( WP_REST_Request $request ) {
        global $wpdb;
        $mode = sanitize_text_field( (string) ( $request->get_param( 'mode' ) ?? 'dry_run' ) );
        if ( ! in_array( $mode, [ 'dry_run', 'apply' ], true ) ) {
            $mode = 'dry_run';
        }
        $timebox = $this->int_param( $request, 'timebox_seconds', 90, 5, 3600 );
        $chunk   = $this->int_param( $request, 'chunk_size', 500, 1, 5000 );
        $max     = $this->int_param( $request, 'max_products', 10000, 1, 200000 );

        $repo = new RepriceAssignmentRepository();
        $assignments = $repo->list_enabled_ordered( 500 );
        if ( empty( $assignments ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'total' => 0, 'enqueued' => 0, 'skipped' => 0, 'items' => [] ], 200 );
        }

        $runsTable = $wpdb->prefix . 'aurora_ops_runs';
        $now       = current_time( 'mysql', true );
        $items     = [];
        $enqueued  = 0;
        $skipped   = 0;
        foreach ( $assignments as $assignment ) {
            $aid = (int) $assignment['id'];
            $dup = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$runsTable} WHERE op_key='repricer_run' AND status IN ('requested','running','partial') AND meta_json LIKE %s LIMIT 1",
                    '%"assignment_id":' . $aid . '%'
                )
            );
            if ( $dup ) {
                $skipped++;
                $items[] = [ 'assignment_id' => $aid, 'run_id' => (int) $dup, 'scheduled' => false, 'reason' => 'duplicate' ];
                continue;
            }
            $payload = [
                'assignment_id'   => $aid,
                'mode'            => $mode,
                'dry_run'         => $mode === 'dry_run',
                'timebox_seconds' => $timebox,
                'chunk_size'      => $chunk,
                'max_products'    => $max,
            ];
            $inserted = $wpdb->insert(
                $runsTable,
                [
                    'op_key'       => 'repricer_run',
                    'action_type'  => 'repricer_run',
                    'status'       => 'requested',
                    'requested_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                    'meta_json'    => wp_json_encode( $payload ),
                ]
            );
            if ( false === $inserted ) {
                $skipped++;
                $items[] = [ 'assignment_id' => $aid, 'run_id' => null, 'scheduled' => false, 'reason' => 'insert_failed' ];
                continue;
            }
            $runId = (int) $wpdb->insert_id;
            $args = [
                [
                    'run_id'  => $runId,
                    'op_key'  => 'repricer_run',
                    'payload' => $payload,
                ],
            ];
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
            }
            $enqueued++;
            $items[] = [ 'assignment_id' => $aid, 'run_id' => $runId, 'scheduled' => true ];
        }

        return new WP_REST_Response( [
            'ok'        => true,
            'total'     => count( $assignments ),
            'enqueued'  => $enqueued,
            'skipped'   => $skipped,
            'items'     => $items,
        ], 200 );
    }

    public function repricer_rollback( WP_REST_Request $request ) {
        global $wpdb;
        $targetRunId = absint( $request->get_param( 'run_id' ) );
        if ( $targetRunId <= 0 ) {
            return new WP_Error( 'aurora_repricer_rollback_bad_request', 'run_id required', [ 'status' => 400 ] );
        }

        $dup = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}aurora_ops_runs WHERE op_key='repricer_rollback' AND status IN ('requested','running','partial') AND meta_json LIKE %s LIMIT 1",
                '%"target_run_id":' . $targetRunId . '%'
            )
        );
        if ( $dup ) {
            error_log( sprintf( '[Aurora] repricer_rollback dedup target_run_id=%d run_id=%d', $targetRunId, (int) $dup ) );
            return new WP_Error( 'aurora_repricer_rollback_duplicate', 'Rollback already pending for target run', [ 'status' => 409, 'run_id' => (int) $dup ] );
        }

        $payload = [
            'target_run_id' => $targetRunId,
            'dry_run'       => $this->bool_param( $request, 'dry_run', false ),
            'chunk_size'    => $this->int_param( $request, 'chunk_size', 200, 1, 5000 ),
        ];

        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'aurora_ops_runs',
            [
                'op_key'       => 'repricer_rollback',
                'action_type'  => 'repricer_rollback',
                'status'       => 'requested',
                'requested_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
                'meta_json'    => wp_json_encode( $payload ),
            ]
        );
        if ( false === $inserted ) {
            return new WP_Error( 'aurora_repricer_rollback_insert_failed', 'Unable to create rollback run', [ 'status' => 500 ] );
        }
        $run_id = (int) $wpdb->insert_id;
        $payload['run_id'] = $run_id;
        $args = [
            [
                'run_id'  => $run_id,
                'op_key'  => 'repricer_rollback',
                'payload' => $payload,
            ],
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
        }
        error_log( sprintf( '[Aurora] repricer_rollback scheduled run_id=%d target_run_id=%d', $run_id, $targetRunId ) );
        return new WP_REST_Response( [ 'ok' => true, 'run_id' => $run_id, 'scheduled' => true ], 200 );
    }

    public function repricer_preview( WP_REST_Request $request ) {
        $assignmentId = absint( $request->get_param( 'assignment_id' ) );
        if ( $assignmentId <= 0 ) {
            return new WP_Error( 'aurora_repricer_preview_bad_request', 'assignment_id required', [ 'status' => 400 ] );
        }
        $limit   = $this->int_param( $request, 'limit', 20, 1, 200 );
        $afterId = absint( $request->get_param( 'after_id' ) );

        $repo = new RepriceAssignmentRepository();
        $assignment = $repo->get( $assignmentId );
        if ( ! $assignment || (int) ( $assignment['is_enabled'] ?? 0 ) !== 1 ) {
            return new WP_Error( 'aurora_repricer_preview_not_found', 'assignment not found', [ 'status' => 404 ] );
        }
        $scope   = is_array( $assignment['scope_json'] ?? null ) ? $assignment['scope_json'] : [];
        $filters = is_array( $assignment['filters_json'] ?? null ) ? $assignment['filters_json'] : [];
        $resolver = new \Aurora\Enterprise\Repricer\RepriceScopeResolver();
        $ids = $resolver->resolve_product_ids( $scope, $filters, $limit, $afterId );

        return new WP_REST_Response( [
            'ok'              => true,
            'assignment_id'   => $assignmentId,
            'scope_type'      => $scope['scope_type'] ?? ( $scope['type'] ?? '' ),
            'selected_count'  => count( $ids ),
            'product_ids'     => array_values( $ids ),
        ], 200 );
    }

    public function repricer_assignments_create( WP_REST_Request $request ) {
        $repo = new RepriceAssignmentRepository();
        $scope = $request->get_param( 'scope' );
        $rule  = $request->get_param( 'rule' );
        $id = $repo->create( [
            'name'       => sanitize_text_field( (string) $request->get_param( 'name' ) ),
            'enabled'    => $this->int_param( $request, 'enabled', 1, 0, 1 ),
            'scope_type' => sanitize_text_field( (string) $request->get_param( 'scope_type' ) ),
            'scope_json' => is_array( $scope ) ? $scope : [],
            'rule_json'  => is_array( $rule ) ? $rule : [],
        ] );
        if ( $id <= 0 ) {
            return new WP_Error( 'aurora_repricer_assignment_create_failed', 'Unable to create assignment', [ 'status' => 500 ] );
        }
        return [ 'ok' => true, 'assignment_id' => $id ];
    }

    public function repricer_assignments_list( WP_REST_Request $request ) {
        $repo = new RepriceAssignmentRepository();
        $limit  = $this->int_param( $request, 'limit', 50, 1, 200 );
        $offset = $this->int_param( $request, 'offset', 0, 0, 1000000 );
        return [
            'items' => $repo->list( $limit, $offset ),
        ];
    }

    public function repricer_assignments_get( WP_REST_Request $request ) {
        $repo = new RepriceAssignmentRepository();
        $id = (int) $request['id'];
        $row = $repo->get( $id );
        if ( ! $row ) {
            return new WP_Error( 'aurora_repricer_assignment_not_found', 'Assignment not found', [ 'status' => 404 ] );
        }
        return $row;
    }

    public function repricer_scheduler_tick( WP_REST_Request $request ) {
        try {
            $scheduler = new \Aurora\Enterprise\Repricer\RepriceScheduler();
            $only = $this->int_param( $request, 'only_assignment_id', 0, 0, 1000000000 );
            $scheduler->handle_tick( $only );
            $last = get_option( 'aurora_repricer_tick_last', [] );
            return new WP_REST_Response( [
                'ok'                    => 1,
                'mode'                  => is_array( $last ) ? ( $last['mode'] ?? 'interval' ) : 'interval',
                'in_window'             => is_array( $last ) ? ( $last['in_window'] ?? null ) : null,
                'enqueued'              => is_array( $last ) ? (int) ( $last['enqueued'] ?? 0 ) : 0,
                'skipped'               => is_array( $last ) ? (int) ( $last['skipped'] ?? 0 ) : 0,
                'skipped_out_of_window' => is_array( $last ) ? (int) ( $last['skipped_out_window'] ?? 0 ) : 0,
                'cursor'                => is_array( $last ) ? (int) ( $last['cursor'] ?? 0 ) : 0,
                'last_error'            => is_array( $last ) ? ( $last['error'] ?? null ) : null,
            ], 200 );
        } catch ( \Throwable $e ) {
            error_log( '[Aurora] repricer_scheduler_tick_failed code=' . (int) $e->getCode() );
            return new WP_Error( 'aurora_repricer_scheduler_tick_failed', 'Scheduler tick failed.', [ 'status' => 500 ] );
        }
    }

    public function trigger_rebuild( WP_REST_Request $request ) {
        $rateLimited = $this->maybe_rate_limit( 'rebuild' );
        if ( is_wp_error( $rateLimited ) ) {
            return $rateLimited;
        }
        return $this->schedule(
            'rebuild',
            [
                'indexer' => sanitize_text_field( (string) $request->get_param( 'indexer' ) ),
            ]
        );
    }

    public function trigger_sweep( WP_REST_Request $request ) {
        $rateLimited = $this->maybe_rate_limit( 'sweep_leases' );
        if ( is_wp_error( $rateLimited ) ) {
            return $rateLimited;
        }
        $payload = [];
        $channel = sanitize_text_field( (string) $request->get_param( 'channel' ) );
        if ( '' !== $channel ) {
            $payload['channel'] = $channel;
        }
        $older = $request->get_param( 'older_than' );
        if ( null !== $older && '' !== $older ) {
            $payload['older_than'] = $this->int_value( $older, 300, 1, 2592000 );
        }
        $shard = $request->get_param( 'shard' );
        if ( null !== $shard && '' !== $shard ) {
            $payload['shard'] = $this->int_value( $shard, 0, 0, 4096 );
        }
        $totalShards = $request->get_param( 'total_shards' );
        if ( null !== $totalShards && '' !== $totalShards ) {
            $payload['total_shards'] = $this->int_value( $totalShards, 1, 1, 4096 );
        }
        return $this->schedule( 'sweep_leases', $payload );
    }

    public function trigger_feed_enqueue( WP_REST_Request $request ) {
        $rateLimited = $this->maybe_rate_limit( 'feed_enqueue' );
        if ( is_wp_error( $rateLimited ) ) {
            return $rateLimited;
        }
        $chunk = $this->int_param( $request, 'chunk_size', 1000, 1, 10000 );
        return $this->schedule(
            'feed_enqueue',
            [
                'chunk_size' => $chunk,
            ]
        );
    }

    public function trigger_feed_run( WP_REST_Request $request ) {
        $rateLimited = $this->maybe_rate_limit( 'feed_run' );
        if ( is_wp_error( $rateLimited ) ) {
            return $rateLimited;
        }
        $payload = [
            'batch'     => $this->int_param( $request, 'batch', 100, 1, 2000 ),
            'max_loops' => $this->int_param( $request, 'max_loops', 1, 1, 100 ),
        ];
        $shard = $request->get_param( 'shard' );
        if ( null !== $shard && '' !== $shard ) {
            $payload['shard'] = $this->int_value( $shard, 0, 0, 4096 );
        }
        $totalShards = $request->get_param( 'total_shards' );
        if ( null !== $totalShards && '' !== $totalShards ) {
            $payload['total_shards'] = $this->int_value( $totalShards, 1, 1, 4096 );
        }
        return $this->schedule( 'feed_run', $payload );
    }

    public function feed_run( WP_REST_Request $request ) {
        global $wpdb;
        $existing = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}aurora_ops_runs WHERE op_key='feed_run' AND status IN ('requested','running','partial') ORDER BY id DESC LIMIT 1"
        );
        if ( $existing ) {
            return new WP_Error( 'aurora_ops_feed_busy', 'Feed run already in progress', [ 'status' => 409, 'run_id' => (int) $existing ] );
        }
        $run    = Ops_Run_Manager::instance();
        $run_id = $run->create_run( 'feed_run', 'feed_run', null, [] );
        if ( $run_id <= 0 ) {
            return new WP_Error( 'aurora_ops_run_create_failed', 'Unable to create ops run row.', [ 'status' => 500 ] );
        }
        $payload = [ 'run_id' => $run_id ];
        $scheduled = $this->schedule( 'feed_run', $payload );
        if ( is_wp_error( $scheduled ) ) {
            return $scheduled;
        }
        return [ 'ok' => true, 'run_id' => $run_id, 'scheduled' => true ];
    }

    private function respond( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result );
    }

    private function schedule( string $op_key, array $payload = [] ) {
        $validation = $this->validate_trigger( $op_key, $payload );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $indexer = isset( $payload['indexer'] ) ? (string) $payload['indexer'] : null;
        $result  = $this->runs->enqueue( $op_key, $indexer, $payload );
        return $this->respond( $result );
    }

    private function validate_trigger( string $op_key, array $payload ) {
        $allowed = [ 'rebuild', 'sweep_leases', 'feed_enqueue', 'feed_run' ];
        if ( ! in_array( $op_key, $allowed, true ) ) {
            return new WP_Error( 'aurora_ops_invalid_op', 'Invalid operation key.', [ 'status' => 400 ] );
        }

        if ( 'rebuild' === $op_key ) {
            $indexer = (string) ( $payload['indexer'] ?? '' );
            if ( ! in_array( $indexer, [ 'price', 'stock', 'visibility', 'all' ], true ) ) {
                return new WP_Error( 'aurora_ops_invalid_indexer', 'Invalid rebuild indexer.', [ 'status' => 400 ] );
            }
        }

        if ( 'feed_enqueue' === $op_key ) {
            $chunk = (int) ( $payload['chunk_size'] ?? 0 );
            if ( $chunk <= 0 || $chunk > 10000 ) {
                return new WP_Error( 'aurora_ops_invalid_chunk', 'chunk_size must be between 1 and 10000.', [ 'status' => 400 ] );
            }
        }

        if ( 'feed_run' === $op_key ) {
            $batch = (int) ( $payload['batch'] ?? 0 );
            $loops = (int) ( $payload['max_loops'] ?? 0 );
            if ( $batch <= 0 || $batch > 2000 || $loops <= 0 || $loops > 100 ) {
                return new WP_Error( 'aurora_ops_invalid_feed_run', 'batch and max_loops are out of range.', [ 'status' => 400 ] );
            }
        }

        if ( 'sweep_leases' === $op_key ) {
            if ( isset( $payload['channel'] ) ) {
                $channel = (string) $payload['channel'];
                if ( ! in_array( $channel, [ 'price', 'stock', 'visibility', 'feed', 'all' ], true ) ) {
                    return new WP_Error( 'aurora_ops_invalid_channel', 'Invalid sweep channel.', [ 'status' => 400 ] );
                }
            }
            if ( isset( $payload['older_than'] ) && ( (int) $payload['older_than'] <= 0 || (int) $payload['older_than'] > 2592000 ) ) {
                return new WP_Error( 'aurora_ops_invalid_older_than', 'older_than is out of range.', [ 'status' => 400 ] );
            }
        }

        if ( isset( $payload['total_shards'] ) ) {
            $totalShards = (int) $payload['total_shards'];
            if ( $totalShards <= 0 || $totalShards > 4096 ) {
                return new WP_Error( 'aurora_ops_invalid_total_shards', 'total_shards is out of range.', [ 'status' => 400 ] );
            }
            if ( isset( $payload['shard'] ) ) {
                $shard = (int) $payload['shard'];
                if ( $shard < 0 || $shard >= $totalShards ) {
                    return new WP_Error( 'aurora_ops_invalid_shard', 'shard must be within total_shards.', [ 'status' => 400 ] );
                }
            }
        }

        return true;
    }

    public function check_permissions( ?WP_REST_Request $request = null ) {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'aurora_rest_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! $this->can_manage_ops() ) {
            return new WP_Error( 'aurora_rest_forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
        }
        if ( ! $this->verify_nonce( $request ) ) {
            return new WP_Error( 'aurora_rest_invalid_nonce', 'Invalid REST nonce.', [ 'status' => 403 ] );
        }
        return true;
    }

    private function verify_nonce( ?WP_REST_Request $request = null ) : bool {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return true;
        }
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
        if ( '' === $nonce && $request ) {
            $nonce = (string) $request->get_header( 'x_wp_nonce' );
        }
        if ( '' === $nonce ) {
            // Keep cookie/session-auth compatible while still enforcing capabilities.
            return true;
        }
        return wp_verify_nonce( $nonce, 'wp_rest' ) > 0;
    }

    private function can_manage_ops() : bool {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    private function rate_limit_disabled() : bool {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        if ( defined( 'AURORA_DISABLE_RATE_LIMIT' ) && AURORA_DISABLE_RATE_LIMIT ) {
            return true;
        }
        $env = getenv( 'AURORA_DISABLE_RATE_LIMIT' );
        if ( false === $env ) {
            return false;
        }
        return in_array( strtolower( (string) $env ), [ '1', 'true', 'yes', 'on' ], true );
    }

    private function maybe_rate_limit( string $opKey ) {
        if ( $this->rate_limit_disabled() ) {
            return true;
        }
        $userId = get_current_user_id();
        if ( $userId <= 0 ) {
            return new WP_Error( 'aurora_rest_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
        }
        $window = (int) apply_filters( 'aurora_ops_rate_limit_window', 5, $opKey, $userId );
        $window = max( 1, min( 60, $window ) );
        $key = 'aurora_ops_rl_' . md5( $userId . '|' . $opKey );
        $last = (int) get_transient( $key );
        $now  = time();
        if ( $last > 0 && ( $now - $last ) < $window ) {
            return new WP_Error(
                'aurora_ops_rate_limited',
                'Too many requests, retry shortly.',
                [
                    'status'      => 429,
                    'retry_after' => ( $window - ( $now - $last ) ),
                ]
            );
        }
        set_transient( $key, (string) $now, $window );
        return true;
    }

    public function trigger( WP_REST_Request $request ) {
        $params  = $request->get_json_params() ?: [];
        $opKey   = isset( $params['op_key'] ) ? sanitize_text_field( $params['op_key'] ) : '';
        $payloadRaw = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : [];

        if ( '' === $opKey ) {
            return new WP_Error( 'aurora_ops_invalid_op', 'Missing op_key.', [ 'status' => 400 ] );
        }

        $rateLimited = $this->maybe_rate_limit( $opKey );
        if ( is_wp_error( $rateLimited ) ) {
            return $rateLimited;
        }

        $payload = $this->sanitize_trigger_payload( $opKey, $payloadRaw );
        $validation = $this->validate_trigger( $opKey, $payload );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $indexer = isset( $payload['indexer'] ) ? (string) $payload['indexer'] : null;
        $result  = $this->runs->enqueue( $opKey, $indexer, $payload );
        return $this->respond( $result );
    }

    private function int_param( WP_REST_Request $request, string $key, int $default, int $min, int $max ) : int {
        return $this->int_value( $request->get_param( $key ), $default, $min, $max );
    }

    private function int_value( $value, int $default, int $min, int $max ) : int {
        if ( null === $value || '' === $value ) {
            return $default;
        }
        $raw = absint( $value );
        if ( $raw < $min ) {
            return $min;
        }
        if ( $raw > $max ) {
            return $max;
        }
        return $raw;
    }

    private function float_param( WP_REST_Request $request, string $key, float $default, float $min, float $max ) : float {
        $value = $request->get_param( $key );
        if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
            return $default;
        }
        $raw = (float) $value;
        if ( $raw < $min ) {
            return $min;
        }
        if ( $raw > $max ) {
            return $max;
        }
        return $raw;
    }

    private function bool_param( WP_REST_Request $request, string $key, bool $default ) : bool {
        $value = $request->get_param( $key );
        if ( null === $value || '' === $value ) {
            return $default;
        }
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value === 1;
        }
        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
                return true;
            }
            if ( in_array( $normalized, [ '0', 'false', 'no', 'off' ], true ) ) {
                return false;
            }
        }
        return $default;
    }

    private function sanitize_run_message( string $message ) : string {
        $clean = sanitize_text_field( wp_strip_all_tags( $message ) );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $clean, 0, 180 );
        }
        return substr( $clean, 0, 180 );
    }

    private function sanitize_trigger_payload( string $opKey, array $payload ) : array {
        switch ( $opKey ) {
            case 'rebuild':
                return [
                    'indexer' => sanitize_text_field( (string) ( $payload['indexer'] ?? 'all' ) ),
                ];
            case 'feed_enqueue':
                return [
                    'chunk_size' => $this->int_value( $payload['chunk_size'] ?? null, 1000, 1, 10000 ),
                ];
            case 'feed_run':
                $sanitized = [
                    'batch'     => $this->int_value( $payload['batch'] ?? null, 100, 1, 2000 ),
                    'max_loops' => $this->int_value( $payload['max_loops'] ?? null, 1, 1, 100 ),
                ];
                if ( array_key_exists( 'shard', $payload ) ) {
                    $sanitized['shard'] = $this->int_value( $payload['shard'], 0, 0, 4096 );
                }
                if ( array_key_exists( 'total_shards', $payload ) ) {
                    $sanitized['total_shards'] = $this->int_value( $payload['total_shards'], 1, 1, 4096 );
                }
                return $sanitized;
            case 'sweep_leases':
                $sanitized = [];
                if ( array_key_exists( 'channel', $payload ) ) {
                    $sanitized['channel'] = sanitize_text_field( (string) $payload['channel'] );
                }
                if ( array_key_exists( 'older_than', $payload ) ) {
                    $sanitized['older_than'] = $this->int_value( $payload['older_than'], 300, 1, 2592000 );
                }
                if ( array_key_exists( 'shard', $payload ) ) {
                    $sanitized['shard'] = $this->int_value( $payload['shard'], 0, 0, 4096 );
                }
                if ( array_key_exists( 'total_shards', $payload ) ) {
                    $sanitized['total_shards'] = $this->int_value( $payload['total_shards'], 1, 1, 4096 );
                }
                return $sanitized;
            default:
                return [];
        }
    }
}
