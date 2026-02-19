<?php
namespace Aurora\Enterprise\Http\Controllers;

use Aurora\Enterprise\Queue\Queue_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Queue_Controller {
    public function register_routes() : void {
        register_rest_route( 'aurora/v1', '/queue/dead', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'get_dead_jobs' ],
        ] );

        register_rest_route( 'aurora/v1', '/queue/retry', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'retry_dead' ],
        ] );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function get_dead_jobs( WP_REST_Request $request ) : WP_REST_Response {
        $queue = sanitize_text_field( (string) $request->get_param( 'queue' ) );
        $limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 20 ) );
        $jobs  = Queue_Manager::instance()->dead( $queue ?: null, $limit );
        return new WP_REST_Response( [ 'jobs' => $jobs ] );
    }

    public function retry_dead( WP_REST_Request $request ) {
        $queue = sanitize_text_field( (string) $request->get_param( 'queue' ) );
        $limit = max( 1, min( 500, (int) $request->get_param( 'limit' ) ?: 100 ) );
        $retried = Queue_Manager::instance()->retryDead( $queue ?: null, $limit );
        if ( $retried <= 0 ) {
            return new WP_Error( 'aurora_queue_retry_empty', __( 'Nessun job dead da riprovare.', 'aurora-enterprise' ) );
        }
        return new WP_REST_Response( [ 'retried' => $retried ] );
    }
}
