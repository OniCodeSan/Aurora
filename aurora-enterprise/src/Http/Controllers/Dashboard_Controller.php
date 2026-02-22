<?php
namespace Aurora\Enterprise\Http\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\CronStatus;
use Aurora\Enterprise\Support\SnapshotVersionGuard;
use Aurora\Enterprise\Support\Config;
use wpdb;

class Dashboard_Controller {
    private SnapshotVersionGuard $guard;

    public function __construct() {
        $this->guard = new SnapshotVersionGuard();
    }

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

        register_rest_route( 'aurora/v1', '/snapshot/check', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'validate_snapshot' ],
        ] );
    }

    public function can_view() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function get_dashboard( WP_REST_Request $request ) : WP_REST_Response {
        /** @var wpdb $wpdb */
        global $wpdb;
        $queueStats = Queue_Manager::instance()->stats();
        $deadFallback = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}product_index_queue WHERE status = 'dead'" ) ?: 0;
        $overShards = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}product_index_queue WHERE status='pending' AND shard >= %d",
Config::totalShards()
            )
        );
        $logs = $wpdb->get_results( "SELECT indexer, level, message, created_at FROM {$wpdb->prefix}product_index_logs ORDER BY id DESC LIMIT 5", ARRAY_A );
        $lastRebuild = [
            'price'      => get_option( 'aurora_last_rebuild_price', '' ),
            'stock'      => get_option( 'aurora_last_rebuild_stock', '' ),
            'visibility' => get_option( 'aurora_last_rebuild_visibility', '' ),
        ];
        $cron = new CronStatus();
        $snapshotReport = $this->guard->report();

        return new WP_REST_Response( [
            'queue' => [
                'price'      => $queueStats['price'] ?? 0,
                'stock'      => $queueStats['stock'] ?? 0,
                'visibility' => $queueStats['visibility'] ?? 0,
                'feed'       => $queueStats['feed'] ?? 0,
                'dead'       => isset( $queueStats['dead'] ) ? (int) $queueStats['dead'] : (int) $deadFallback,
            ],
            'logs' => $logs,
            'lastRebuild' => $lastRebuild,
            'cron' => $cron->formatted(),
            'cronStatuses' => $cron->statuses(),
            'snapshot' => array_merge( $snapshotReport, [ 'pending_out_of_range' => $overShards ] ),
        ] );
    }

    public function trigger_rebuild( WP_REST_Request $request ) {
        if ( ! function_exists( 'wp_schedule_single_event' ) ) {
            return new WP_Error( 'aurora_no_schedule', __( 'Cron non disponibile.', 'aurora-enterprise' ) );
        }
        wp_schedule_single_event( time(), 'aurora_rebuild_async', [ 'all' ] );
        return new WP_REST_Response( [ 'status' => 'queued' ] );
    }

    public function validate_snapshot( WP_REST_Request $request ) {
        $report = $this->guard->report();
        if ( ! $report['aligned'] ) {
            return new WP_Error( 'aurora_snapshot_mismatch', __( 'Snapshot cut non allineato.', 'aurora-enterprise' ), $report );
        }
        if ( $report['pending_out_of_range'] > 0 ) {
            return new WP_Error( 'aurora_shard_mismatch', __( 'Shard mismatch: alcuni job sono fuori range.', 'aurora-enterprise' ), $report );
        }
        return new WP_REST_Response( $report );
    }
}
