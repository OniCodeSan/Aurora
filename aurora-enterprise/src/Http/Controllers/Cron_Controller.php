<?php
namespace Aurora\Enterprise\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Aurora\Enterprise\Support\CronStatus;

class Cron_Controller {
    public function register_routes() : void {
        register_rest_route( 'aurora/v1', '/cron', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'update_cron' ],
        ] );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function update_cron( WP_REST_Request $request ) {
        $key     = sanitize_key( $request->get_param( 'key' ) );
        $status  = sanitize_key( $request->get_param( 'status' ) );
        $interval = sanitize_text_field( $request->get_param( 'interval' ) );
        $service = new CronStatus();
        $updated = $service->update( $key, [ 'status' => $status, 'interval' => $interval ] );
        if ( ! $updated ) {
            return new WP_Error( 'aurora_invalid_cron', __( 'Cron job non valido.', 'aurora-enterprise' ) );
        }
        return new WP_REST_Response( $service->formatted() );
    }
}
