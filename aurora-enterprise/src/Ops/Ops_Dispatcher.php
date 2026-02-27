<?php
namespace Aurora\Enterprise\Ops;

use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\Config;
use function error_log;

class Ops_Dispatcher {
    public function hooks() : void {
        add_action( 'aurora_ops_dispatch', [ $this, 'handle' ], 10, 3 );
    }

    public function handle( $run_id = null, $action_type = null, $params = [] ) : void {
        [ $run_id, $action_type, $params ] = $this->normalizeDispatchArgs( $run_id, $action_type, $params );
        if ( $run_id <= 0 ) {
            error_log( '[Aurora] Ops_Dispatcher skipped: invalid run_id' );
            return;
        }
        if ( '' === $action_type ) {
            error_log( '[Aurora] Ops_Dispatcher skipped: missing action_type' );
            return;
        }
        $runs = Ops_Run_Manager::instance();
        $runs->mark_running( $run_id );
        try {
            if ( 'sweep_leases' === $action_type ) {
                $result = $this->sweep();
            } else {
                $result = [ 'message' => 'Unsupported op key: ' . $action_type ];
            }
            $runs->mark_success( $run_id, $result );
        } catch ( \Throwable $exception ) {
            $runs->mark_error( $run_id, $exception->getMessage() );
        }
    }

    /**
     * @deprecated Keep for backward compatibility with old async callbacks.
     */
    public function dispatch( $run_id = null, $action_type = null, $params = [] ) : void {
        $this->handle( $run_id, $action_type, $params );
    }

    private function normalizeDispatchArgs( $run_id, $action_type, $params ) : array {
        // Backward compatibility: Action Scheduler may dispatch a single associative payload.
        if ( is_array( $run_id ) ) {
            $legacy = $run_id;
            $run_id = $legacy['run_id'] ?? 0;
            $action_type = $legacy['action_type'] ?? ( $legacy['op_key'] ?? '' );
            $params = $legacy['params'] ?? ( $legacy['payload'] ?? [] );
        }

        if ( ! is_array( $params ) ) {
            $params = [];
        }

        return [ (int) $run_id, (string) $action_type, $params ];
    }

    private function sweep() : array {
        $queue = Queue_Manager::instance()->driver();
        if ( ! $queue instanceof \Aurora\Enterprise\Queue\DatabaseQueue ) {
            return [ 'message' => 'Sweep skipped: driver is not database.' ];
        }
        $ttl = Config::leaseTtlSeconds();
        $requeued = 0;
        $dead = 0;
        $totalShards = Config::totalShards();
        for ( $shard = 0; $shard < $totalShards; $shard++ ) {
            $result = $queue->sweepExpiredLeases( null, $ttl, $shard );
            $requeued += (int) ( $result['requeued'] ?? 0 );
            $dead     += (int) ( $result['dead'] ?? 0 );
        }
        return [
            'message'  => sprintf( 'Sweep completed. requeued=%d, dead=%d', $requeued, $dead ),
            'requeued' => $requeued,
            'dead'     => $dead,
        ];
    }
}
