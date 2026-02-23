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
        ] );
    }

    public function get_status() : WP_REST_Response {
        return new WP_REST_Response( $this->provider->get_status() );
    }

    public function trigger_rebuild( WP_REST_Request $request ) {
        $indexer = $request->get_param( 'indexer' );
        return $this->respond( $this->runs->enqueue( 'rebuild', $indexer ) );
    }

    public function trigger_sweep() {
        return $this->respond( $this->runs->enqueue( 'sweep_leases' ) );
    }

    public function trigger_feed_enqueue( WP_REST_Request $request ) {
        $chunk = (int) $request->get_param( 'chunk_size' );
        return $this->respond( $this->runs->enqueue( 'feed_enqueue', null, [ 'chunk_size' => $chunk ?: 1000 ] ) );
    }

    public function trigger_feed_run() {
        return $this->respond( $this->runs->enqueue( 'feed_run' ) );
    }

    private function respond( $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $result );
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
}
