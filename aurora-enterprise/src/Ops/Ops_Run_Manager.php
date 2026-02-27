<?php
namespace Aurora\Enterprise\Ops;

use WP_Error;

class Ops_Run_Manager {
    private const TABLE = 'aurora_ops_runs';
    private const STATUS_CACHE_KEY = 'aurora_system_status';
    private const SUPPORTED_OPS = [ 'rebuild', 'sweep_leases', 'feed_enqueue', 'feed_run' ];
    private static ?Ops_Run_Manager $instance = null;

    public static function instance() : Ops_Run_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function enqueue( string $actionType, ?string $indexer = null, array $payload = [] ) {
        if ( ! in_array( $actionType, self::SUPPORTED_OPS, true ) ) {
            return new WP_Error( 'aurora_ops_invalid_action', 'Unsupported operation key.', [ 'status' => 400 ] );
        }

        if ( 'rebuild' === $actionType ) {
            $payload['indexer'] = $indexer ?? ( $payload['indexer'] ?? 'all' );
        }

        $opKey = $this->resolve_key( $actionType, $indexer );
        $meta  = array_merge( [ 'op_key' => $opKey ], $payload );
        $runId = $this->create_run( $opKey, $actionType, $indexer, $meta );
        if ( $runId <= 0 ) {
            return new WP_Error( 'aurora_ops_run_create_failed', 'Unable to create ops run row.', [ 'status' => 500 ] );
        }

        $args  = [
            [
                'run_id'  => $runId,
                'op_key'  => $opKey,
                'payload' => $payload,
            ],
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'aurora_ops_dispatch', $args, 'aurora' ) ) {
                return new WP_Error( 'aurora_ops_duplicate_action', 'Action already scheduled for this run_id.', [ 'status' => 409 ] );
            }
            $actionId = (int) as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
            if ( $actionId <= 0 ) {
                $this->mark_error( $runId, 'Unable to schedule async action.' );
                return new WP_Error( 'aurora_ops_schedule_failed', 'Failed scheduling async operation.', [ 'status' => 500 ] );
            }
        } else {
            $this->mark_error( $runId, 'Action Scheduler missing.' );
            return new WP_Error( 'aurora_ops_scheduler_missing', 'Action Scheduler required for ops triggers.' );
        }
        $this->invalidate_status_cache();
        return [
            'ok'        => true,
            'run_id'    => $runId,
            'scheduled' => true,
            'op_key'    => $opKey,
        ];
    }

    public function mark_running( int $runId ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql', true );
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'running', started_at = %s, updated_at = %s WHERE id = %d AND status = 'requested'",
                $now, $now, $runId
            )
        );
        $this->invalidate_status_cache();
        return 1 === (int) $updated;
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
        $this->invalidate_status_cache();
    }

    public function mark_error( int $runId, string $error ) : void {
        $now = current_time( 'mysql', true );
        $this->update( $runId, [
            'status'      => 'error',
            'finished_at' => $now,
            'error'       => $error,
            'updated_at'  => $now,
        ] );
        $this->invalidate_status_cache();
    }

    public function find( int $runId ) : ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, op_key, action_type, indexer, status, requested_at, started_at, finished_at, message, error, meta_json FROM {$table} WHERE id = %d LIMIT 1",
                $runId
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function recent_runs( int $limit = 5 ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, op_key, action_type, indexer, status, requested_at, started_at, finished_at, message, error FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    private function resolve_key( string $actionType, ?string $indexer ) : string {
        return $actionType;
    }

    private function create_run( string $opKey, string $actionType, ?string $indexer, array $meta ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time( 'mysql', true );
        $result = $wpdb->insert(
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
        if ( false === $result ) {
            return 0;
        }
        $this->invalidate_status_cache();
        return (int) $wpdb->insert_id;
    }

    private function update( int $runId, array $data ) : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update( $table, $data, [ 'id' => $runId ] );
    }

    private function invalidate_status_cache() : void {
        delete_transient( self::STATUS_CACHE_KEY );
    }
}
