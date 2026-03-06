<?php
namespace Aurora\Enterprise\Ops\Rest;

use Aurora\Enterprise\Ops\System_Status_Provider;
use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Ops\Ops_Dispatcher;
use Aurora\Enterprise\Repricer\RepriceAssignmentRepository;
use Aurora\Enterprise\Repricer\RepriceRuleRepository;
use Aurora\Enterprise\Repricer\RepriceRuleEngine;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Ops_Controller {
    private const FEED_INTEGRATIONS_OPTION = 'aurora_feed_marketplace_integrations';
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

        register_rest_route( 'aurora/v1', '/feed/integrations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'feed_integrations_get' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/feed/integrations', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'feed_integrations_update' ],
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

        register_rest_route( 'aurora/v1', '/repricer/rules', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'repricer_rules_list' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_rules_create' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'repricer_rules_get' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_rules_get' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/(?P<id>\\d+)', [
            'methods'             => [ 'PUT', 'PATCH' ],
            'callback'            => [ $this, 'repricer_rules_update' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/(?P<id>\\d+)/preview-scope', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_rules_preview_scope' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/options', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'repricer_rules_options' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        register_rest_route( 'aurora/v1', '/repricer/rules/options', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'repricer_rules_options' ],
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
        $payload = array_merge( $payload, $this->repricer_runtime_options( $request ) );
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
        $payload = array_merge( $payload, $this->repricer_runtime_options( $request ) );

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
            $payload = array_merge( $payload, $this->repricer_runtime_options( $request ) );
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
            $payload['run_id'] = $runId;
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

        $raw = $request->get_json_params();
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            $raw = $request->get_params();
        }
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return $this->repricer_assignments_list( $request );
        }

        $scope = is_array( $raw['scope'] ?? null ) ? $raw['scope'] : [];
        $rule  = is_array( $raw['rule'] ?? null ) ? $raw['rule'] : [];
        $name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'aurora_repricer_assignment_bad_request', 'name is required', [ 'status' => 400 ] );
        }

        $scopeType = sanitize_text_field( (string) ( $raw['scope_type'] ?? ( $scope['scope_type'] ?? ( $scope['type'] ?? '' ) ) ) );
        if ( '' === $scopeType ) {
            return new WP_Error( 'aurora_repricer_assignment_bad_scope_type', 'scope_type is required', [ 'status' => 400 ] );
        }

        if ( ! isset( $scope['scope_type'] ) && ! isset( $scope['type'] ) ) {
            $scope['scope_type'] = $scopeType;
        }
        $scopeProducts = array_values( array_filter( array_map( 'absint', (array) ( $scope['products'] ?? [] ) ) ) );
        $scopeCategories = array_values( array_filter( array_map( 'absint', (array) ( $scope['categories'] ?? [] ) ) ) );
        if ( empty( $scopeProducts ) && empty( $scopeCategories ) ) {
            return new WP_Error( 'aurora_repricer_assignment_empty_scope', 'scope requires products or categories', [ 'status' => 400 ] );
        }
        if ( ! empty( $scopeProducts ) ) {
            $scope['products'] = $scopeProducts;
        }
        if ( ! empty( $scopeCategories ) ) {
            $scope['categories'] = $scopeCategories;
        }

        $id = $repo->create( [
            'name'       => $name,
            'enabled'    => isset( $raw['enabled'] ) ? max( 0, min( 1, (int) $raw['enabled'] ) ) : $this->int_param( $request, 'enabled', 1, 0, 1 ),
            'priority'   => isset( $raw['priority'] ) ? max( 0, min( 1000000, (int) $raw['priority'] ) ) : 100,
            'scope_type' => $scopeType,
            'scope_json' => $scope,
            'rule_json'  => $rule,
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

    public function repricer_rules_list( WP_REST_Request $request ) {
        $repo = new RepriceRuleRepository();
        $limit  = $this->int_param( $request, 'limit', 50, 1, 200 );
        $offset = $this->int_param( $request, 'offset', 0, 0, 1000000 );
        return [
            'items' => $repo->list( $limit, $offset ),
        ];
    }

    public function repricer_rules_get( WP_REST_Request $request ) {
        $repo = new RepriceRuleRepository();
        $id = (int) $request['id'];
        $row = $repo->get( $id );
        if ( ! $row ) {
            return new WP_Error( 'aurora_repricer_rule_not_found', 'Rule not found', [ 'status' => 404 ] );
        }
        return $row;
    }

    public function repricer_rules_create( WP_REST_Request $request ) {
        $raw = $request->get_json_params();
        if ( ( ! is_array( $raw ) || empty( $raw ) ) && is_array( $request->get_param( 'rule' ) ) ) {
            $raw = [ 'rule' => $request->get_param( 'rule' ) ];
        }
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return $this->repricer_rules_list( $request );
        }
        if ( isset( $raw['rule'] ) && is_array( $raw['rule'] ) && empty( $raw['rule'] ) ) {
            return $this->repricer_rules_list( $request );
        }
        $ruleJson = $this->sanitize_rule_payload( $request );
        if ( is_wp_error( $ruleJson ) ) {
            return $ruleJson;
        }
        $repo = new RepriceRuleRepository();
        $id = $repo->create( $ruleJson, get_current_user_id() );
        if ( $id <= 0 ) {
            return new WP_Error( 'aurora_repricer_rule_create_failed', 'Unable to create rule', [ 'status' => 500 ] );
        }
        return new WP_REST_Response( [ 'ok' => true, 'rule_id' => $id ], 200 );
    }

    public function repricer_rules_update( WP_REST_Request $request ) {
        $id = (int) $request['id'];
        if ( $id <= 0 ) {
            return new WP_Error( 'aurora_repricer_rule_bad_request', 'Invalid rule id', [ 'status' => 400 ] );
        }
        $ruleJson = $this->sanitize_rule_payload( $request );
        if ( is_wp_error( $ruleJson ) ) {
            return $ruleJson;
        }
        $repo = new RepriceRuleRepository();
        $ok = $repo->update( $id, $ruleJson, get_current_user_id() );
        if ( ! $ok ) {
            return new WP_Error( 'aurora_repricer_rule_update_failed', 'Unable to update rule', [ 'status' => 500 ] );
        }
        return new WP_REST_Response( [ 'ok' => true, 'rule_id' => $id ], 200 );
    }

    public function repricer_rules_preview_scope( WP_REST_Request $request ) {
        $repo = new RepriceRuleRepository();
        $id = (int) $request['id'];
        $row = $repo->get( $id );
        if ( ! $row ) {
            return new WP_Error( 'aurora_repricer_rule_not_found', 'Rule not found', [ 'status' => 404 ] );
        }
        $ruleJson = is_array( $row['rule_json'] ?? null ) ? $row['rule_json'] : [];
        $scope = is_array( $ruleJson['scope'] ?? null ) ? $ruleJson['scope'] : [];
        $engine = new RepriceRuleEngine();
        $limit = $this->int_param( $request, 'limit', 200, 1, 500 );
        $preview = $engine->resolve_scope_products( $scope, $limit );
        return new WP_REST_Response(
            [
                'ok'            => true,
                'rule_id'       => $id,
                'resolved_count'=> (int) ( $preview['resolved_count'] ?? 0 ),
                'sample_ids'    => array_values( $preview['sample_ids'] ?? [] ),
                'warnings'      => array_values( $preview['warnings'] ?? [] ),
            ],
            200
        );
    }

    public function repricer_rules_options( WP_REST_Request $request ) {
        $limit = $this->int_param( $request, 'limit', 200, 20, 500 );
        $productsLimit = $this->int_param( $request, 'products_limit', 200, 20, 500 );

        $productCategories = $this->taxonomy_term_options_by_id( 'product_cat', $limit );
        $brandTaxonomy = $this->detect_brand_taxonomy();
        $brands = null !== $brandTaxonomy ? $this->taxonomy_term_options_by_id( $brandTaxonomy, $limit ) : [];
        $productTypeTerms = $this->taxonomy_term_options_by_slug( 'product_type', $limit );
        $productTypeMeta = $this->meta_value_options( '_aurora_product_type', $limit );
        $supplierIds = $this->meta_value_options( '_aurora_supplier_id', $limit );
        $lines = $this->meta_value_options( '_aurora_line', $limit );

        $productTypeMap = [];
        foreach ( array_merge( $productTypeTerms, $productTypeMeta ) as $entry ) {
            $value = sanitize_text_field( (string) ( $entry['value'] ?? '' ) );
            if ( '' === $value ) {
                continue;
            }
            $productTypeMap[ $value ] = [
                'value' => $value,
                'label' => sanitize_text_field( (string) ( $entry['label'] ?? $value ) ),
            ];
        }

        $products = [];
        $productQuery = new \WP_Query(
            [
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'posts_per_page'      => $productsLimit,
                'orderby'             => 'ID',
                'order'               => 'DESC',
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ]
        );
        if ( ! empty( $productQuery->posts ) ) {
            foreach ( $productQuery->posts as $productId ) {
                $id = (int) $productId;
                if ( $id <= 0 ) {
                    continue;
                }
                $title = sanitize_text_field( (string) get_the_title( $id ) );
                $products[] = [
                    'id'    => $id,
                    'label' => '#' . $id . ' ' . ( '' !== $title ? $title : 'Prodotto' ),
                ];
            }
        }
        wp_reset_postdata();

        return new WP_REST_Response(
            [
                'ok' => true,
                'options' => [
                    'categories'     => $productCategories,
                    'brands'         => $brands,
                    'product_types'  => array_values( $productTypeMap ),
                    'suppliers'      => $supplierIds,
                    'lines'          => $lines,
                    'products'       => $products,
                    'brand_taxonomy' => $brandTaxonomy,
                ],
            ],
            200
        );
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

    public function feed_integrations_get( WP_REST_Request $request ) {
        return new WP_REST_Response(
            [
                'ok'           => true,
                'integrations' => $this->mask_feed_integrations( $this->load_feed_integrations() ),
            ],
            200
        );
    }

    public function feed_integrations_update( WP_REST_Request $request ) {
        $raw = $request->get_json_params();
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        $incoming = is_array( $raw['integrations'] ?? null ) ? $raw['integrations'] : $raw;
        if ( empty( $incoming ) ) {
            $param = $request->get_param( 'integrations' );
            if ( is_array( $param ) ) {
                $incoming = $param;
            }
        }
        if ( empty( $incoming ) ) {
            $amazonParam = $request->get_param( 'amazon' );
            $ebayParam = $request->get_param( 'ebay' );
            if ( is_array( $amazonParam ) || is_array( $ebayParam ) ) {
                $incoming = [
                    'amazon' => is_array( $amazonParam ) ? $amazonParam : [],
                    'ebay'   => is_array( $ebayParam ) ? $ebayParam : [],
                ];
            }
        }
        $current = $this->load_feed_integrations();
        $next = $current;

        $amazon = is_array( $incoming['amazon'] ?? null ) ? $incoming['amazon'] : [];
        $next['amazon']['seller_id'] = sanitize_text_field( (string) ( $amazon['seller_id'] ?? $next['amazon']['seller_id'] ) );
        $next['amazon']['marketplace_id'] = sanitize_text_field( (string) ( $amazon['marketplace_id'] ?? $next['amazon']['marketplace_id'] ) );
        $next['amazon']['client_id'] = sanitize_text_field( (string) ( $amazon['client_id'] ?? $next['amazon']['client_id'] ) );
        $next['amazon']['client_secret'] = $this->merge_secret_value( $next['amazon']['client_secret'], $amazon['client_secret'] ?? null );
        $next['amazon']['refresh_token'] = $this->merge_secret_value( $next['amazon']['refresh_token'], $amazon['refresh_token'] ?? null );

        $ebay = is_array( $incoming['ebay'] ?? null ) ? $incoming['ebay'] : [];
        $next['ebay']['merchant_id'] = sanitize_text_field( (string) ( $ebay['merchant_id'] ?? $next['ebay']['merchant_id'] ) );
        $next['ebay']['site_id'] = sanitize_text_field( (string) ( $ebay['site_id'] ?? $next['ebay']['site_id'] ) );
        $next['ebay']['app_id'] = sanitize_text_field( (string) ( $ebay['app_id'] ?? $next['ebay']['app_id'] ) );
        $next['ebay']['dev_id'] = sanitize_text_field( (string) ( $ebay['dev_id'] ?? $next['ebay']['dev_id'] ) );
        $next['ebay']['cert_id'] = $this->merge_secret_value( $next['ebay']['cert_id'], $ebay['cert_id'] ?? null );
        $next['ebay']['user_token'] = $this->merge_secret_value( $next['ebay']['user_token'], $ebay['user_token'] ?? null );

        $next['updated_at'] = current_time( 'mysql', true );
        update_option( self::FEED_INTEGRATIONS_OPTION, $next, false );

        return new WP_REST_Response(
            [
                'ok'           => true,
                'integrations' => $this->mask_feed_integrations( $next ),
            ],
            200
        );
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

    /**
     * @return array<string,mixed>
     */
    private function repricer_runtime_options( WP_REST_Request $request ) : array {
        $payload = [];
        $strategyRaw = $request->get_param( 'strategy' );
        if ( null !== $strategyRaw && '' !== $strategyRaw ) {
            $strategy = sanitize_text_field( (string) $strategyRaw );
            if ( in_array( $strategy, [ 'maintain_margin', 'match_competitor', 'beat_competitor' ], true ) ) {
                $payload['strategy'] = $strategy;
            }
        }
        $marginModeRaw = $request->get_param( 'margin_mode' );
        if ( null !== $marginModeRaw && '' !== $marginModeRaw ) {
            $marginMode = sanitize_text_field( (string) $marginModeRaw );
            if ( in_array( $marginMode, [ 'clamp', 'block' ], true ) ) {
                $payload['margin_mode'] = $marginMode;
            }
        }
        $roundingModeRaw = $request->get_param( 'rounding_mode' );
        if ( null !== $roundingModeRaw && '' !== $roundingModeRaw ) {
            $roundingMode = sanitize_text_field( (string) $roundingModeRaw );
            if ( in_array( $roundingMode, [ 'none', '.99', '99', '.49', '49', 'step' ], true ) ) {
                $payload['rounding_mode'] = $roundingMode;
            }
        }

        $floatKeys = [
            'rounding_step' => [ 0.0, 1000000.0 ],
            'max_raise_pct' => [ 0.0, 1000.0 ],
            'max_drop_pct' => [ 0.0, 1000.0 ],
            'hard_max_raise_pct' => [ 0.0, 1000.0 ],
            'hard_max_drop_pct' => [ 0.0, 1000.0 ],
            'beat_delta_abs' => [ 0.0, 1000000.0 ],
            'beat_delta_pct' => [ 0.0, 1000.0 ],
            'target_margin_percent' => [ 0.0, 1000.0 ],
            'target_margin_abs' => [ 0.0, 1000000.0 ],
            'competitor_price' => [ 0.0, 1000000.0 ],
            'min_price' => [ 0.0, 1000000.0 ],
            'max_price' => [ 0.0, 1000000.0 ],
            'map_price' => [ 0.0, 1000000.0 ],
        ];
        foreach ( $floatKeys as $key => $range ) {
            $raw = $request->get_param( $key );
            if ( null === $raw || '' === $raw ) {
                continue;
            }
            $payload[ $key ] = $this->float_param( $request, $key, 0.0, (float) $range[0], (float) $range[1] );
        }

        if ( ! array_key_exists( 'hard_max_raise_pct', $payload ) ) {
            $aliasRaise = $request->get_param( 'max_increase_pct' );
            if ( null !== $aliasRaise && '' !== $aliasRaise ) {
                $payload['hard_max_raise_pct'] = $this->float_param( $request, 'max_increase_pct', 0.0, 0.0, 1000.0 );
            }
        }
        if ( ! array_key_exists( 'hard_max_drop_pct', $payload ) ) {
            $aliasDrop = $request->get_param( 'max_decrease_pct' );
            if ( null !== $aliasDrop && '' !== $aliasDrop ) {
                $payload['hard_max_drop_pct'] = $this->float_param( $request, 'max_decrease_pct', 0.0, 0.0, 1000.0 );
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function sanitize_rule_payload( WP_REST_Request $request ) {
        $raw = $request->get_json_params();
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        if ( empty( $raw ) && is_array( $request->get_param( 'rule' ) ) ) {
            $raw = [ 'rule' => $request->get_param( 'rule' ) ];
        }
        $rule = is_array( $raw['rule'] ?? null ) ? $raw['rule'] : $raw;
        $ruleMeta = is_array( $rule['rule_meta'] ?? null ) ? $rule['rule_meta'] : [];

        $name = sanitize_text_field( (string) ( $ruleMeta['name'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'aurora_repricer_rule_name_required', 'rule_meta.name is required', [ 'status' => 400 ] );
        }
        $priority = max( 0, min( 1000000, (int) ( $ruleMeta['priority'] ?? 100 ) ) );
        $enabled = $this->bool_value( $ruleMeta['enabled'] ?? true, true );
        $exclusive = $this->bool_value( $ruleMeta['exclusive'] ?? false, false );

        $scopeRaw = is_array( $rule['scope'] ?? null ) ? $rule['scope'] : [];
        $erpStockCondition = (string) ( $scopeRaw['erp_stock_condition'] ?? 'any' );
        $scope = [
            'product_ids'         => $this->sanitize_int_array( $scopeRaw['product_ids'] ?? [] ),
            'brand_ids'           => $this->sanitize_int_array( $scopeRaw['brand_ids'] ?? [] ),
            'brand_terms'         => $this->sanitize_text_array( $scopeRaw['brand_terms'] ?? [] ),
            'category_ids'        => $this->sanitize_int_array( $scopeRaw['category_ids'] ?? [] ),
            'supplier_ids'        => $this->sanitize_text_array( $scopeRaw['supplier_ids'] ?? [] ),
            'product_type'        => $this->sanitize_text_array( $scopeRaw['product_type'] ?? [] ),
            'line'                => $this->sanitize_text_array( $scopeRaw['line'] ?? [] ),
            'erp_stock_condition' => in_array( $erpStockCondition, [ 'any', 'eq_0', 'gt_0' ], true ) ? $erpStockCondition : 'any',
            'urgent_only'         => $this->bool_value( $scopeRaw['urgent_only'] ?? false, false ),
        ];

        $conditionsRaw = is_array( $rule['conditions'] ?? null ) ? $rule['conditions'] : [];
        $conditions = [
            'cost_min'                => $this->float_or_null( $conditionsRaw['cost_min'] ?? null, 0.0, 100000000.0 ),
            'cost_max'                => $this->float_or_null( $conditionsRaw['cost_max'] ?? null, 0.0, 100000000.0 ),
            'competitor_position_min' => $this->int_or_null( $conditionsRaw['competitor_position_min'] ?? null, 0, 1000000 ),
            'competitor_position_max' => $this->int_or_null( $conditionsRaw['competitor_position_max'] ?? null, 0, 1000000 ),
            'min_reviews'             => $this->int_or_null( $conditionsRaw['min_reviews'] ?? null, 0, 1000000 ),
            'rotation_index'          => $this->sanitize_operator_condition( $conditionsRaw['rotation_index'] ?? null ),
            'sold_last_30_days'       => $this->sanitize_operator_condition( $conditionsRaw['sold_last_30_days'] ?? null ),
            'top_search_only'         => $this->bool_value( $conditionsRaw['top_search_only'] ?? false, false ),
        ];

        $strategyRaw = is_array( $rule['pricing_strategy'] ?? null ) ? $rule['pricing_strategy'] : [];
        $strategyType = sanitize_text_field( (string) ( $strategyRaw['type'] ?? 'manual' ) );
        if ( ! in_array( $strategyType, [ 'markup', 'margin', 'manual', 'competitor' ], true ) ) {
            return new WP_Error( 'aurora_repricer_rule_invalid_strategy', 'pricing_strategy.type is invalid', [ 'status' => 400 ] );
        }
        $manualMode = (string) ( $strategyRaw['manual_mode'] ?? 'keep' );
        $competitorMode = (string) ( $strategyRaw['competitor_mode'] ?? 'match' );
        $pricingStrategy = [
            'type'                  => $strategyType,
            'markup_percent'        => $this->float_or_null( $strategyRaw['markup_percent'] ?? null, 0.0, 10000.0 ),
            'markup_abs'            => $this->float_or_null( $strategyRaw['markup_abs'] ?? null, 0.0, 1000000.0 ),
            'margin_target_percent' => $this->float_or_null( $strategyRaw['margin_target_percent'] ?? null, 0.0, 99.0 ),
            'manual_mode'           => in_array( $manualMode, [ 'keep', 'override' ], true ) ? $manualMode : 'keep',
            'manual_price'          => $this->float_or_null( $strategyRaw['manual_price'] ?? null, 0.0, 1000000.0 ),
            'competitor_mode'       => in_array( $competitorMode, [ 'match', 'beat' ], true ) ? $competitorMode : 'match',
            'competitor_delta'      => $this->float_or_null( $strategyRaw['competitor_delta'] ?? null, 0.0, 1000000.0 ),
        ];

        $guardrailsRaw = is_array( $rule['guardrails'] ?? null ) ? $rule['guardrails'] : [];
        $rounding = sanitize_text_field( (string) ( $guardrailsRaw['rounding'] ?? 'none' ) );
        if ( ! in_array( $rounding, [ 'none', 'x.99', 'x.49', 'step' ], true ) ) {
            $rounding = 'none';
        }
        $marginMode = (string) ( $guardrailsRaw['margin_mode'] ?? 'clamp' );
        $guardrails = [
            'min_price'         => $this->float_or_null( $guardrailsRaw['min_price'] ?? null, 0.0, 1000000.0 ),
            'max_price'         => $this->float_or_null( $guardrailsRaw['max_price'] ?? null, 0.0, 1000000.0 ),
            'min_margin_percent'=> $this->float_or_null( $guardrailsRaw['min_margin_percent'] ?? null, 0.0, 1000.0 ),
            'min_margin_abs'    => $this->float_or_null( $guardrailsRaw['min_margin_abs'] ?? null, 0.0, 1000000.0 ),
            'max_raise_percent' => $this->float_or_null( $guardrailsRaw['max_raise_percent'] ?? null, 0.0, 1000.0 ),
            'max_drop_percent'  => $this->float_or_null( $guardrailsRaw['max_drop_percent'] ?? null, 0.0, 1000.0 ),
            'rounding'          => $rounding,
            'step_value'        => $this->float_or_null( $guardrailsRaw['step_value'] ?? null, 0.0, 1000000.0 ),
            'margin_mode'       => in_array( $marginMode, [ 'clamp', 'block' ], true ) ? $marginMode : 'clamp',
        ];

        $inventoryRaw = is_array( $rule['inventory_rules'] ?? null ) ? $rule['inventory_rules'] : [];
        $inventory = [
            'max_qty_per_order' => $this->int_or_null( $inventoryRaw['max_qty_per_order'] ?? null, 0, 1000000 ),
            'apply_if_stock_gt' => $this->int_or_null( $inventoryRaw['apply_if_stock_gt'] ?? null, 0, 1000000 ),
        ];

        $validityRaw = is_array( $rule['validity'] ?? null ) ? $rule['validity'] : [];
        $validity = [
            'start_at' => $this->datetime_or_null( $validityRaw['start_at'] ?? null ),
            'end_at'   => $this->datetime_or_null( $validityRaw['end_at'] ?? null ),
        ];

        return [
            'rule_meta' => [
                'name'      => $name,
                'priority'  => $priority,
                'enabled'   => $enabled,
                'exclusive' => $exclusive,
            ],
            'scope'            => $scope,
            'conditions'       => $conditions,
            'pricing_strategy' => $pricingStrategy,
            'guardrails'       => $guardrails,
            'inventory_rules'  => $inventory,
            'validity'         => $validity,
        ];
    }

    /**
     * @param mixed $value
     */
    private function bool_value( $value, bool $default ) : bool {
        if ( null === $value || '' === $value ) {
            return $default;
        }
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value === 1;
        }
        $clean = strtolower( trim( (string) $value ) );
        if ( in_array( $clean, [ '1', 'true', 'yes', 'on' ], true ) ) {
            return true;
        }
        if ( in_array( $clean, [ '0', 'false', 'no', 'off' ], true ) ) {
            return false;
        }
        return $default;
    }

    /**
     * @param mixed $value
     * @return array<int>
     */
    private function sanitize_int_array( $value ) : array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $items = array_values( array_filter( array_map( 'intval', $value ), static fn( int $v ) : bool => $v > 0 ) );
        return array_values( array_unique( $items ) );
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function sanitize_text_array( $value ) : array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $items = [];
        foreach ( $value as $item ) {
            $clean = sanitize_text_field( (string) $item );
            if ( '' !== $clean ) {
                $items[] = $clean;
            }
        }
        return array_values( array_unique( $items ) );
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function taxonomy_term_options_by_slug( string $taxonomy, int $limit ) : array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }
        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'number'     => $limit,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'fields'     => 'all',
            ]
        );
        if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }
        $items = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }
            $slug = sanitize_title( (string) $term->slug );
            if ( '' === $slug ) {
                continue;
            }
            $items[] = [
                'value' => $slug,
                'label' => sanitize_text_field( (string) $term->name ),
            ];
        }
        return $items;
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    private function taxonomy_term_options_by_id( string $taxonomy, int $limit ) : array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }
        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'number'     => $limit,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'fields'     => 'all',
            ]
        );
        if ( ! is_array( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }
        $items = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }
            $termId = (int) $term->term_id;
            if ( $termId <= 0 ) {
                continue;
            }
            $items[] = [
                'id'   => $termId,
                'name' => sanitize_text_field( (string) $term->name ),
            ];
        }
        return $items;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function meta_value_options( string $metaKey, int $limit ) : array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.meta_key = %s
               AND pm.meta_value <> ''
             ORDER BY pm.meta_value ASC
             LIMIT %d",
            $metaKey,
            $limit
        );
        $rows = $wpdb->get_col( $sql ) ?: [];
        $items = [];
        foreach ( $rows as $row ) {
            $value = sanitize_text_field( (string) $row );
            if ( '' === $value ) {
                continue;
            }
            $items[] = [
                'value' => $value,
                'label' => $value,
            ];
        }
        return $items;
    }

    private function detect_brand_taxonomy() : ?string {
        if ( taxonomy_exists( 'product_brand' ) ) {
            return 'product_brand';
        }
        if ( taxonomy_exists( 'pa_brand' ) ) {
            return 'pa_brand';
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function float_or_null( $value, float $min, float $max ) : ?float {
        if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
            return null;
        }
        $float = (float) $value;
        if ( $float < $min ) {
            $float = $min;
        }
        if ( $float > $max ) {
            $float = $max;
        }
        return $float;
    }

    /**
     * @param mixed $value
     */
    private function int_or_null( $value, int $min, int $max ) : ?int {
        if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
            return null;
        }
        $int = (int) $value;
        if ( $int < $min ) {
            $int = $min;
        }
        if ( $int > $max ) {
            $int = $max;
        }
        return $int;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>|null
     */
    private function sanitize_operator_condition( $value ) : ?array {
        if ( ! is_array( $value ) ) {
            return null;
        }
        $operator = sanitize_text_field( (string) ( $value['operator'] ?? '' ) );
        if ( ! in_array( $operator, [ '>', '>=', '<', '<=', '=', '!=' ], true ) ) {
            return null;
        }
        $float = $this->float_or_null( $value['value'] ?? null, -100000000.0, 100000000.0 );
        if ( null === $float ) {
            return null;
        }
        return [
            'operator' => $operator,
            'value'    => $float,
        ];
    }

    /**
     * @param mixed $value
     */
    private function datetime_or_null( $value ) : ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }
        $stamp = strtotime( (string) $value );
        if ( false === $stamp ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $stamp );
    }

    private function int_param( WP_REST_Request $request, string $key, int $default, int $min, int $max ) : int {
        return $this->int_value( $request->get_param( $key ), $default, $min, $max );
    }

    /**
     * @return array<string,mixed>
     */
    private function default_feed_integrations() : array {
        return [
            'amazon' => [
                'seller_id'      => '',
                'marketplace_id' => '',
                'client_id'      => '',
                'client_secret'  => '',
                'refresh_token'  => '',
            ],
            'ebay' => [
                'merchant_id' => '',
                'site_id'     => '',
                'app_id'      => '',
                'dev_id'      => '',
                'cert_id'     => '',
                'user_token'  => '',
            ],
            'updated_at' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function load_feed_integrations() : array {
        $defaults = $this->default_feed_integrations();
        $stored = get_option( self::FEED_INTEGRATIONS_OPTION, [] );
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }
        $amazon = is_array( $stored['amazon'] ?? null ) ? $stored['amazon'] : [];
        $ebay = is_array( $stored['ebay'] ?? null ) ? $stored['ebay'] : [];
        return [
            'amazon' => [
                'seller_id'      => sanitize_text_field( (string) ( $amazon['seller_id'] ?? '' ) ),
                'marketplace_id' => sanitize_text_field( (string) ( $amazon['marketplace_id'] ?? '' ) ),
                'client_id'      => sanitize_text_field( (string) ( $amazon['client_id'] ?? '' ) ),
                'client_secret'  => $this->sanitize_secret_value( $amazon['client_secret'] ?? '' ),
                'refresh_token'  => $this->sanitize_secret_value( $amazon['refresh_token'] ?? '' ),
            ],
            'ebay' => [
                'merchant_id' => sanitize_text_field( (string) ( $ebay['merchant_id'] ?? '' ) ),
                'site_id'     => sanitize_text_field( (string) ( $ebay['site_id'] ?? '' ) ),
                'app_id'      => sanitize_text_field( (string) ( $ebay['app_id'] ?? '' ) ),
                'dev_id'      => sanitize_text_field( (string) ( $ebay['dev_id'] ?? '' ) ),
                'cert_id'     => $this->sanitize_secret_value( $ebay['cert_id'] ?? '' ),
                'user_token'  => $this->sanitize_secret_value( $ebay['user_token'] ?? '' ),
            ],
            'updated_at' => sanitize_text_field( (string) ( $stored['updated_at'] ?? '' ) ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mask_feed_integrations( array $data ) : array {
        $amazon = is_array( $data['amazon'] ?? null ) ? $data['amazon'] : [];
        $ebay = is_array( $data['ebay'] ?? null ) ? $data['ebay'] : [];
        return [
            'amazon' => [
                'seller_id'         => sanitize_text_field( (string) ( $amazon['seller_id'] ?? '' ) ),
                'marketplace_id'    => sanitize_text_field( (string) ( $amazon['marketplace_id'] ?? '' ) ),
                'client_id'         => sanitize_text_field( (string) ( $amazon['client_id'] ?? '' ) ),
                'has_client_secret' => '' !== (string) ( $amazon['client_secret'] ?? '' ),
                'has_refresh_token' => '' !== (string) ( $amazon['refresh_token'] ?? '' ),
                'client_secret'     => $this->mask_secret_value( (string) ( $amazon['client_secret'] ?? '' ) ),
                'refresh_token'     => $this->mask_secret_value( (string) ( $amazon['refresh_token'] ?? '' ) ),
            ],
            'ebay' => [
                'merchant_id'    => sanitize_text_field( (string) ( $ebay['merchant_id'] ?? '' ) ),
                'site_id'        => sanitize_text_field( (string) ( $ebay['site_id'] ?? '' ) ),
                'app_id'         => sanitize_text_field( (string) ( $ebay['app_id'] ?? '' ) ),
                'dev_id'         => sanitize_text_field( (string) ( $ebay['dev_id'] ?? '' ) ),
                'has_cert_id'    => '' !== (string) ( $ebay['cert_id'] ?? '' ),
                'has_user_token' => '' !== (string) ( $ebay['user_token'] ?? '' ),
                'cert_id'        => $this->mask_secret_value( (string) ( $ebay['cert_id'] ?? '' ) ),
                'user_token'     => $this->mask_secret_value( (string) ( $ebay['user_token'] ?? '' ) ),
            ],
            'updated_at' => sanitize_text_field( (string) ( $data['updated_at'] ?? '' ) ),
        ];
    }

    /**
     * @param mixed $value
     */
    private function sanitize_secret_value( $value ) : string {
        $clean = trim( (string) $value );
        $clean = preg_replace( '/[\r\n\t]+/', '', $clean ) ?? '';
        if ( strlen( $clean ) > 4096 ) {
            $clean = substr( $clean, 0, 4096 );
        }
        return $clean;
    }

    /**
     * @param mixed $incoming
     */
    private function merge_secret_value( string $current, $incoming ) : string {
        if ( null === $incoming ) {
            return $current;
        }
        $clean = $this->sanitize_secret_value( $incoming );
        if ( '' === $clean ) {
            return $current;
        }
        return $clean;
    }

    private function mask_secret_value( string $secret ) : string {
        $len = strlen( $secret );
        if ( $len <= 0 ) {
            return '';
        }
        if ( $len <= 4 ) {
            return str_repeat( '*', $len );
        }
        return substr( $secret, 0, 2 ) . str_repeat( '*', max( 2, $len - 4 ) ) . substr( $secret, -2 );
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
