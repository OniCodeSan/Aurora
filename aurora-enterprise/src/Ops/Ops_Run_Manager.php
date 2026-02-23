<?php
namespace Aurora\Enterprise\Ops;

use WP_Error;

class Ops_Run_Manager {
    private const TABLE = 'aurora_ops_runs';
    private static ?Ops_Run_Manager $instance = null;

    public static function instance() : Ops_Run_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function enqueue( string $actionType, ?string $indexer = null, array $payload = [] ) {
        $opKey = $this->resolve_key( $actionType, $indexer );
        $meta  = array_merge( [ 'op_key' => $opKey ], $payload );
        $runId = $this->create_run( $opKey, $actionType, $indexer, $meta );
        $args  = [
            'run_id'  => $runId,
            'op_key'  => $opKey,
            'payload' => $payload,
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora_ops' );
        } else {
            return new WP_Error( 'aurora_ops_scheduler_missing', 'Action Scheduler required for ops triggers.' );
        }
        return [
            'run_id' => $runId,
            'status' => 'requested',
            'op_key' => $opKey,
        ];
    }

    public function mark_running( int $runId ) : void {
        $this->update( $runId, [
            'status'     => 'running',
            'started_at' => current_time( 'mysql', true ),
            'updated_at' => current_time( 'mysql', true ),
        ] );
    }

    public function mark_success( int $runId, array $meta ) : void {
        $now = current_time( 'mysql', true );
        $this->update( $runId, [
            'status'      => 'success',
            'finished_at' => $now,
            'message'     => $meta['message'] ?? null,
            'meta_json'   => wp_json_encode( $meta ),
            'updated_at'  => $now,
        ] );
    }

    public function mark_error( int $runId, string $error ) : void {
        $now = current_time( 'mysql', true );
        $this->update( $runId, [
            'status'      => 'error',
            'finished_at' => $now,
            'error'       => $error,
            'updated_at'  => $now,
        ] );
    }

    public function recent_runs( int $limit = 5 ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, op_key, action_type, indexer, status, requested_at, started_at, finished_at, message FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    private function resolve_key( string $actionType, ?string $indexer ) : string {
        if ( 'rebuild' === $actionType && $indexer ) {
            return 'rebuild_' . $indexer;
        }
        return $actionType;
    }

    private function create_run( string $opKey, string $actionType, ?string $indexer, array $meta ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql', true );
        $wpdb->insert(
            $table,
            [
                'op_key'       => $opKey,
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
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    private function update( int $runId, array $data ) : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update( $table, $data, [ 'id' => $runId ] );
    }
}
