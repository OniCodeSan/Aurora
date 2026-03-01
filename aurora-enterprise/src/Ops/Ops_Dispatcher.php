<?php
namespace Aurora\Enterprise\Ops;

use function error_log;

class Ops_Dispatcher {
    private Ops_Run_Manager $runs;
    private Ops_Executor $executor;

    public function __construct( ?Ops_Run_Manager $runs = null, ?Ops_Executor $executor = null ) {
        $this->runs     = $runs ?? Ops_Run_Manager::instance();
        $this->executor = $executor ?? new Ops_Executor();
    }

    public function hooks() : void {
        add_action( 'aurora_ops_dispatch', [ $this, 'handle' ], 10, 3 );
    }

    public function handle( $args = null, $legacy_op_key = null, $legacy_payload = [] ) : void {
        [ $run_id, $op_key, $payload ] = $this->normalize_dispatch_args( $args, $legacy_op_key, $legacy_payload );
        if ( str_starts_with( $op_key, 'rebuild_' ) ) {
            $payload['indexer'] = $payload['indexer'] ?? substr( $op_key, 8 );
            $op_key = 'rebuild';
        }
        if ( $run_id <= 0 ) {
            error_log( '[Aurora] Ops_Dispatcher skipped: invalid run_id' );
            return;
        }
        if ( ! in_array( $op_key, [ 'rebuild', 'sweep_leases', 'feed_enqueue', 'feed_run', 'repricer_run' ], true ) ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher skipped: run_id=%d invalid op_key=%s', $run_id, $op_key ) );
            return;
        }

        $run = $this->runs->find( $run_id );
        if ( ! is_array( $run ) ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher skipped: run_id=%d not found op_key=%s', $run_id, $op_key ) );
            return;
        }

        $status = (string) ( $run['status'] ?? '' );
        if ( in_array( $status, [ 'success', 'error' ], true ) ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher idempotent skip: run_id=%d op_key=%s status=%s', $run_id, $op_key, $status ) );
            return;
        }
        if ( 'running' === $status ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher concurrent skip: run_id=%d op_key=%s already running', $run_id, $op_key ) );
            return;
        }
        if ( ! in_array( $status, [ 'requested', 'partial' ], true ) ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher skipped: run_id=%d op_key=%s unexpected status=%s', $run_id, $op_key, $status ) );
            return;
        }
        if ( ! $this->runs->mark_running( $run_id ) ) {
            error_log( sprintf( '[Aurora] Ops_Dispatcher skipped: run_id=%d op_key=%s could not transition to running', $run_id, $op_key ) );
            return;
        }

        error_log( sprintf( '[Aurora] Ops_Dispatcher start: run_id=%d op_key=%s', $run_id, $op_key ) );
        try {
            $result = $this->executor->execute( $op_key, $payload );
            $updated = $this->runs->find( $run_id );
            $statusAfter = (string) ( $updated['status'] ?? '' );
            if ( in_array( $statusAfter, [ 'success', 'error', 'partial' ], true ) ) {
                error_log( sprintf( '[Aurora] Ops_Dispatcher skip mark_success (already %s): run_id=%d op_key=%s', $statusAfter, $run_id, $op_key ) );
            } else {
                $this->runs->mark_success( $run_id, $result );
                error_log( sprintf( '[Aurora] Ops_Dispatcher success: run_id=%d op_key=%s message=%s', $run_id, $op_key, (string) ( $result['message'] ?? '' ) ) );
            }
        } catch ( \Throwable $exception ) {
            $this->runs->mark_error( $run_id, $exception->getMessage() );
            error_log( sprintf( '[Aurora] Ops_Dispatcher error: run_id=%d op_key=%s error=%s', $run_id, $op_key, $exception->getMessage() ) );
        }
    }

    /**
     * @deprecated Keep for backward compatibility with old async callbacks.
     */
    public function dispatch( $run_id = null, $action_type = null, $params = [] ) : void {
        $this->handle( $run_id, $action_type, $params );
    }

    private function normalize_dispatch_args( $args, $legacy_op_key, $legacy_payload ) : array {
        // Canonical payload: [{ run_id, op_key, payload }]
        if ( is_array( $args ) && isset( $args[0] ) && is_array( $args[0] ) ) {
            $canonical = $args[0];
            $run_id = (int) ( $canonical['run_id'] ?? 0 );
            $op_key = (string) ( $canonical['op_key'] ?? '' );
            $payload = $canonical['payload'] ?? [];
            return [ $run_id, $op_key, is_array( $payload ) ? $payload : [] ];
        }

        // Legacy payload shape: { run_id, op_key, payload }
        if ( is_array( $args ) && isset( $args['run_id'] ) ) {
            $run_id = (int) ( $args['run_id'] ?? 0 );
            $op_key = (string) ( $args['op_key'] ?? ( $args['action_type'] ?? '' ) );
            $payload = $args['payload'] ?? ( $args['params'] ?? [] );
            return [ $run_id, $op_key, is_array( $payload ) ? $payload : [] ];
        }

        // Legacy callback signature: handle($run_id, $op_key, $payload)
        if ( is_scalar( $args ) ) {
            $payload = is_array( $legacy_payload ) ? $legacy_payload : [];
            return [ (int) $args, (string) $legacy_op_key, $payload ];
        }

        return [ 0, '', [] ];
    }
}
