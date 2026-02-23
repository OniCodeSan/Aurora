<?php
namespace Aurora\Enterprise\Ops;

use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\Config;

class Ops_Dispatcher {
    public function hooks() : void {
        add_action( 'aurora_ops_dispatch', [ $this, 'dispatch' ], 10, 1 );
    }

    public function dispatch( array $args ) : void {
        $runId = (int) ( $args['run_id'] ?? 0 );
        $opKey = $args['op_key'] ?? '';
        if ( ! $runId || ! $opKey ) {
            return;
        }
        $runs = Ops_Run_Manager::instance();
        $runs->mark_running( $runId );
        try {
            if ( 'sweep_leases' === $opKey ) {
                $result = $this->sweep();
            } else {
                $result = [ 'message' => 'Unsupported op key: ' . $opKey ];
            }
            $runs->mark_success( $runId, $result );
        } catch ( \Throwable $exception ) {
            $runs->mark_error( $runId, $exception->getMessage() );
        }
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
