<?php
namespace Aurora\Enterprise\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\CronStatus;

class Dashboard_Controller {
    public function register_routes() : void {
        register_rest_route( 'aurora/v1', '/dashboard', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_view' ],
            'callback' => [ $this, 'get_dashboard' ],
        ] );

        register_rest_route( 'aurora/v1', '/rebuild', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'trigger_rebuild' ],
        ] );
    }

    public function can_view() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function get_dashboard( WP_REST_Request $request ) : WP_REST_Response {
        global $wpdb;
        $queue = Queue_Manager::instance()->stats();
        $deadCount = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}product_index_queue WHERE status = 'dead'" ) ?: 0;
        $logs = $wpdb->get_results( "SELECT indexer, level, message, created_at FROM {$wpdb->prefix}product_index_logs ORDER BY id DESC LIMIT 5", ARRAY_A );
        $lastRebuild = [
            'price'      => get_option( 'aurora_last_rebuild_price', '' ),
            'stock'      => get_option( 'aurora_last_rebuild_stock', '' ),
            'visibility' => get_option( 'aurora_last_rebuild_visibility', '' ),
        ];
        $cron = new CronStatus();
        return new WP_REST_Response( [
            'queue' => [
                'price'      => $queue['price'] ?? 0,
                'stock'      => $queue['stock'] ?? 0,
                'visibility' => $queue['visibility'] ?? 0,
                'dead'       => (int) $deadCount,
            ],
            'logs' => $logs,
            'lastRebuild' => $lastRebuild,
            'cron' => $cron->formatted(),
            'cronStatuses' => $cron->statuses(),
        ] );
    }

    public function trigger_rebuild( WP_REST_Request $request ) {
        if ( ! function_exists( 'wp_schedule_single_event' ) ) {
            return new WP_Error( 'aurora_no_schedule', __( 'Cron non disponibile.', 'aurora-enterprise' ) );
        }
        wp_schedule_single_event( time(), 'aurora_rebuild_async', [ 'all' ] );
        return new WP_REST_Response( [ 'status' => 'queued' ] );
    }
}
