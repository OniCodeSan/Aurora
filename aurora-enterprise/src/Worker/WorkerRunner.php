<?php
namespace Aurora\Enterprise\Worker;

use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Indexer\PriceIndexer;
use Aurora\Enterprise\Indexer\StockIndexer;
use Aurora\Enterprise\Indexer\VisibilityIndexer;
use Aurora\Enterprise\Support\CronStatus;

class WorkerRunner {
    private string $target;
    private int $batchSize;
    private int $maxLoops;

    public function __construct( string $target = 'all', int $batchSize = 750, int $maxLoops = 1 ) {
        $this->target    = $target;
        $this->batchSize = $batchSize;
        $this->maxLoops  = $maxLoops;
    }

    public function run() : int {
        $queue   = Queue_Manager::instance();
        $indexers = [
            'price'      => new PriceIndexer(),
            'stock'      => new StockIndexer(),
            'visibility' => new VisibilityIndexer(),
        ];
        $processed = 0;
        $cronStatus = new CronStatus();
        for ( $loop = 0; $loop < $this->maxLoops; $loop++ ) {
            foreach ( $indexers as $key => $indexer ) {
                if ( 'all' !== $this->target && $this->target !== $key ) {
                    continue;
                }
                $jobs = $queue->reserveBatch( $indexer->getChannel(), $this->batchSize );
                if ( empty( $jobs ) ) {
                    continue;
                }
                $payloads = array_map( static fn( $job ) => $job->data, $jobs );
                try {
                    $indexer->processBatch( $payloads );
                    foreach ( $jobs as $job ) {
                        $queue->ack( $job );
                    }
                    $cronStatus->markRun( $key . '_worker' );
                    $processed += count( $jobs );
                } catch ( \Throwable $exception ) {
                    foreach ( $jobs as $job ) {
                        $queue->fail( $job, $exception->getMessage(), true );
                    }
                }
            }
        }
        return $processed;
    }
}
