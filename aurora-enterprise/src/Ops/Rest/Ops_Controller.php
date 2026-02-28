<?php
namespace Aurora\Enterprise\Ops\Rest;

use Aurora\Enterprise\Ops\System_Status_Provider;
use Aurora\Enterprise\Ops\Ops_Run_Manager;
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
    }

    public function get_status() : WP_REST_Response {
        return new WP_REST_Response( $this->provider->get_status() );
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
