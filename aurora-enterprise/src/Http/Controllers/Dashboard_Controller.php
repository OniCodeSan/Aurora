<?php
namespace Aurora\Enterprise\Http\Controllers;

use Aurora\Enterprise\Ops\Dashboard_Data_Provider;
use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Repricer\RepriceScheduler;
use Aurora\Enterprise\Support\SnapshotVersionGuard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Dashboard_Controller {
    private Dashboard_Data_Provider $provider;
    private SnapshotVersionGuard $guard;

    public function __construct() {
        $this->provider = new Dashboard_Data_Provider();
        $this->guard    = new SnapshotVersionGuard();
    }

    public function register_routes() : void {
        register_rest_route(
            'aurora/v1',
            '/dashboard/summary',
            [
                'methods'             => 'GET',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'summary' ],
            ]
        );

        register_rest_route(
            'aurora/v1',
            '/dashboard/runs',
            [
                'methods'             => 'GET',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'runs' ],
            ]
        );

        register_rest_route(
            'aurora/v1',
            '/dashboard/events',
            [
                'methods'             => 'GET',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'events' ],
            ]
        );

        register_rest_route(
            'aurora/v1',
            '/dashboard/action',
            [
                'methods'             => 'POST',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'action' ],
            ]
        );

        // Backward-compatible route kept for existing integrations.
        register_rest_route(
            'aurora/v1',
            '/dashboard',
            [
                'methods'             => 'GET',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'summary' ],
            ]
        );

        // Backward-compatible route kept for existing integrations.
        register_rest_route(
            'aurora/v1',
            '/rebuild',
            [
                'methods'             => 'POST',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'trigger_rebuild' ],
            ]
        );

        // Backward-compatible route kept for existing integrations.
        register_rest_route(
            'aurora/v1',
            '/snapshot/check',
            [
                'methods'             => 'GET',
                'permission_callback' => [ $this, 'can_manage' ],
                'callback'            => [ $this, 'validate_snapshot' ],
            ]
        );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_options' );
    }

    public function summary( WP_REST_Request $request ) : WP_REST_Response {
        return new WP_REST_Response(
            [
                'summary' => $this->provider->get_summary(),
            ],
            200
        );
    }

    public function runs( WP_REST_Request $request ) : WP_REST_Response {
        $limit = max( 1, min( 100, absint( $request->get_param( 'limit' ) ?: 20 ) ) );
        return new WP_REST_Response(
            [
                'runs'  => $this->provider->get_runs( $limit ),
                'limit' => $limit,
            ],
            200
        );
    }

    public function events( WP_REST_Request $request ) : WP_REST_Response {
        $limit = max( 1, min( 100, absint( $request->get_param( 'limit' ) ?: 10 ) ) );
        return new WP_REST_Response(
            [
                'events' => $this->provider->get_events( $limit ),
                'limit'  => $limit,
            ],
            200
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function action( WP_REST_Request $request ) {
        $action = sanitize_key( (string) $request->get_param( 'action' ) );
        $allowed = [ 'tick_scheduler', 'repricer_tick', 'feed_enqueue', 'feed_run', 'rebuild', 'sweep_leases' ];
        if ( '' === $action || ! in_array( $action, $allowed, true ) ) {
            return new WP_Error(
                'invalid_action',
                'Invalid dashboard action.',
                [ 'status' => 400 ]
            );
        }

        $rate = $this->check_rate_limit( $action );
        if ( is_wp_error( $rate ) ) {
            return $rate;
        }

        $result = $this->dispatch_action( $action, $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->provider->clear_cache();
        delete_transient( 'aurora_dashboard_summary' );
        delete_transient( 'aurora_dashboard_runs' );

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => 'Action executed.',
                'data'    => $result,
            ],
            200
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function trigger_rebuild( WP_REST_Request $request ) {
        $request->set_param( 'action', 'rebuild' );
        return $this->action( $request );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function validate_snapshot( WP_REST_Request $request ) {
        $report = $this->guard->report();
        if ( ! ( $report['aligned'] ?? false ) ) {
            return new WP_Error(
                'aurora_snapshot_mismatch',
                'Snapshot cut non allineato.',
                [ 'status' => 409, 'data' => $report ]
            );
        }
        return new WP_REST_Response( $report, 200 );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function dispatch_action( string $action, WP_REST_Request $request ) {
        if ( ! class_exists( Ops_Run_Manager::class ) ) {
            return new WP_Error(
                'class_not_found',
                'Ops run manager not available.',
                [ 'status' => 500 ]
            );
        }

        $runs = Ops_Run_Manager::instance();

        switch ( $action ) {
            case 'rebuild':
                $indexer = sanitize_key( (string) $request->get_param( 'indexer' ) );
                if ( ! in_array( $indexer, [ 'all', 'price', 'stock', 'visibility' ], true ) ) {
                    $indexer = 'all';
                }
                return $runs->enqueue( 'rebuild', $indexer, [ 'indexer' => $indexer ] );

            case 'feed_enqueue':
                $chunk = (int) $request->get_param( 'chunk_size' );
                $chunk = $chunk > 0 ? $chunk : 1000;
                $chunk = max( 1, min( 10000, $chunk ) );
                return $runs->enqueue( 'feed_enqueue', null, [ 'chunk_size' => $chunk ] );

            case 'feed_run':
                $batch = (int) $request->get_param( 'batch' );
                $loops = (int) $request->get_param( 'max_loops' );
                $batch = $batch > 0 ? $batch : 100;
                $loops = $loops > 0 ? $loops : 1;
                $batch = max( 1, min( 2000, $batch ) );
                $loops = max( 1, min( 100, $loops ) );
                return $runs->enqueue(
                    'feed_run',
                    null,
                    [
                        'batch'     => $batch,
                        'max_loops' => $loops,
                    ]
                );

            case 'sweep_leases':
                $channel = sanitize_key( (string) $request->get_param( 'channel' ) );
                if ( ! in_array( $channel, [ 'all', 'price', 'stock', 'visibility', 'feed' ], true ) ) {
                    $channel = 'all';
                }
                return $runs->enqueue( 'sweep_leases', null, [ 'channel' => $channel ] );

            case 'repricer_tick':
            case 'tick_scheduler':
                if ( ! class_exists( RepriceScheduler::class ) ) {
                    return new WP_Error(
                        'class_not_found',
                        'Reprice scheduler not available.',
                        [ 'status' => 500 ]
                    );
                }
                $onlyAssignment = absint( $request->get_param( 'only_assignment_id' ) );
                $scheduler = new RepriceScheduler();
                if ( ! method_exists( $scheduler, 'handle_tick' ) ) {
                    return new WP_Error(
                        'class_not_found',
                        'Reprice scheduler tick not available.',
                        [ 'status' => 500 ]
                    );
                }
                $scheduler->handle_tick( $onlyAssignment );
                $lastTick = get_option( 'aurora_repricer_tick_last', [] );
                return [
                    'ok'                 => true,
                    'only_assignment_id' => $onlyAssignment,
                    'tick'               => is_array( $lastTick ) ? $lastTick : [],
                ];
        }

        return new WP_Error(
            'invalid_action',
            'Invalid dashboard action.',
            [ 'status' => 400 ]
        );
    }

    private function check_rate_limit( string $action ) {
        $userId = (int) get_current_user_id();
        $key = sprintf( 'aurora_dashboard_rate_%d_%s', $userId, $action );
        $until = (int) get_transient( $key );
        $now = time();
        if ( $until > $now ) {
            return new WP_Error(
                'rate_limited',
                'Action rate limited.',
                [
                    'status'      => 429,
                    'retry_after' => $until - $now,
                ]
            );
        }
        set_transient( $key, $now + 5, 5 );
        return true;
    }
}
