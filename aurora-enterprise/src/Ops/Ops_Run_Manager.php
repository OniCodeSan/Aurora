<?php
namespace Aurora\Enterprise\Ops;

use WP_Error;

class Ops_Run_Manager {
    private static ?Ops_Run_Manager $instance = null;

    public static function instance() : Ops_Run_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function enqueue( string $actionType, ?string $indexer = null, array $payload = [] ) {
        $runId = $this->create_run( $actionType, $indexer, $payload );
        $args  = [
            'run_id'      => $runId,
            'action_type' => $actionType,
            'indexer'     => $indexer,
            'payload'     => $payload,
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora_ops' );
        } else {
            return new WP_Error( 'aurora_ops_scheduler_missing', 'Action Scheduler required for ops triggers.' );
        }
        return [
            'run_id' => $runId,
            'status' => 'requested',
        ];
    }

    private function create_run( string $actionType, ?string $indexer, array $meta ) : int {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_ops_runs';
        $now   = current_time( 'mysql', true );
        $wpdb->insert(
            $table,
            [
                'action_type'  => $actionType,
                'indexer'      => $indexer,
                'status'       => 'requested',
                'requested_at' => $now,
                'started_at'   => null,
                'finished_at'  => null,
                'message'      => null,
                'error'        => null,
                'meta_json'    => wp_json_encode( $meta ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }
}
