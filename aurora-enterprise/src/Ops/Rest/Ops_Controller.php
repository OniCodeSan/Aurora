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

    public function repricer_run( WP_REST_Request $request ) {
        global $wpdb;
        $existing = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}aurora_ops_runs WHERE op_key='repricer_run' AND status IN ('requested','running','partial') ORDER BY id DESC LIMIT 1"
        );
        if ( $existing ) {
            return new WP_Error( 'aurora_ops_repricer_busy', 'Repricer run already in progress', [ 'status' => 409, 'run_id' => (int) $existing ] );
        }

        $assignment_id = (int) ( $request['assignment_id'] ?? 0 );
        $payload = [
            'max_products'       => (int) ( $request['max_products'] ?? 10000 ),
            'chunk_size'         => (int) ( $request['chunk_size'] ?? 500 ),
            'timebox_seconds'    => (int) ( $request['timebox_seconds'] ?? 90 ),
            'min_margin_percent' => isset( $request['min_margin_percent'] ) ? (float) $request['min_margin_percent'] : 0.0,
            'min_margin_abs'     => isset( $request['min_margin_abs'] ) ? (float) $request['min_margin_abs'] : 0.0,
            'dry_run'            => array_key_exists( 'dry_run', $request->get_json_params() ?? [] ) ? (bool) $request['dry_run'] : true,
            'mode'               => (string) ( $request['mode'] ?? ( ( array_key_exists( 'dry_run', $request->get_json_params() ?? [] ) && ! $request['dry_run'] ) ? 'apply' : 'dry_run' ) ),
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

    public function repricer_assignments_create( WP_REST_Request $request ) {
        $repo = new RepriceAssignmentRepository();
        $id = $repo->create( [
            'name'       => (string) $request['name'],
            'enabled'    => isset( $request['enabled'] ) ? (int) $request['enabled'] : 1,
            'scope_type' => (string) $request['scope_type'],
            'scope_json' => $request['scope'] ?? [],
            'rule_json'  => $request['rule'] ?? [],
        ] );
        if ( $id <= 0 ) {
            return new WP_Error( 'aurora_repricer_assignment_create_failed', 'Unable to create assignment', [ 'status' => 500 ] );
        }
        return [ 'ok' => true, 'assignment_id' => $id ];
    }

    public function repricer_assignments_list( WP_REST_Request $request ) {
        $repo = new RepriceAssignmentRepository();
        $limit = (int) ( $request['limit'] ?? 50 );
        $offset = (int) ( $request['offset'] ?? 0 );
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

    public function trigger_rebuild( WP_REST_Request $request ) {
        return $this->schedule(
            'rebuild',
            [
                'indexer' => (string) $request->get_param( 'indexer' ),
            ]
        );
    }

    public function trigger_sweep( WP_REST_Request $request ) {
        $payload = [];
        foreach ( [ 'channel', 'older_than', 'shard', 'total_shards' ] as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value ) {
                $payload[ $key ] = $value;
            }
        }
        return $this->schedule( 'sweep_leases', $payload );
    }

    public function trigger_feed_enqueue( WP_REST_Request $request ) {
        $chunk = (int) $request->get_param( 'chunk_size' );
        return $this->schedule(
            'feed_enqueue',
            [
                'chunk_size' => $chunk > 0 ? $chunk : 1000,
            ]
        );
    }

    public function trigger_feed_run( WP_REST_Request $request ) {
        $payload = [
            'batch'     => (int) $request->get_param( 'batch' ),
            'max_loops' => (int) $request->get_param( 'max_loops' ),
        ];
        foreach ( [ 'shard', 'total_shards' ] as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && '' !== $value ) {
                $payload[ $key ] = $value;
            }
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
            if ( $chunk <= 0 ) {
                return new WP_Error( 'aurora_ops_invalid_chunk', 'chunk_size must be > 0.', [ 'status' => 400 ] );
            }
        }

        if ( 'feed_run' === $op_key ) {
            $batch = (int) ( $payload['batch'] ?? 0 );
            $loops = (int) ( $payload['max_loops'] ?? 0 );
            if ( $batch <= 0 || $loops <= 0 ) {
                return new WP_Error( 'aurora_ops_invalid_feed_run', 'batch and max_loops must be > 0.', [ 'status' => 400 ] );
            }
        }

        return true;
    }

    public function check_permissions() : bool {
        return current_user_can( 'manage_woocommerce' ) && $this->verify_nonce();
    }

    private function verify_nonce() : bool {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
            return wp_verify_nonce( $nonce, 'wp_rest' ) > 0;
        }
        return true;
    }

    public function trigger( WP_REST_Request $request ) {
        $params  = $request->get_json_params() ?: [];
        $opKey   = isset( $params['op_key'] ) ? sanitize_text_field( $params['op_key'] ) : '';
        $payload = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : [];

        if ( '' === $opKey ) {
            return new WP_Error( 'aurora_ops_invalid_op', 'Missing op_key.', [ 'status' => 400 ] );
        }

        $validation = $this->validate_trigger( $opKey, $payload );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $indexer = isset( $payload['indexer'] ) ? (string) $payload['indexer'] : null;
        $result  = $this->runs->enqueue( $opKey, $indexer, $payload );
        return $this->respond( $result );
    }
}
