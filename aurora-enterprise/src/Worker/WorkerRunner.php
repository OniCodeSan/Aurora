<?php
namespace Aurora\Enterprise\Worker;

use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Indexer\PriceIndexer;
use Aurora\Enterprise\Indexer\StockIndexer;
use Aurora\Enterprise\Indexer\VisibilityIndexer;
use Aurora\Enterprise\Indexer\FeedIndexer;
use Aurora\Enterprise\Support\CronStatus;
use Aurora\Enterprise\Support\Config;

use wpdb;

use function array_fill;
use function array_merge;
use function current_time;
use function gmdate;
use function implode;
use function sprintf;
use function time;

class WorkerRunner {
    private string $target;
    private int $batchSize;
    private int $maxLoops;
    private ?int $simulateCrashAfter;
    private ?int $shardFilter;
    private int $totalShards;

    public function __construct( string $target = 'all', int $batchSize = 750, int $maxLoops = 1, ?int $simulateCrashAfter = null, ?int $shardFilter = null, ?int $totalShards = null ) {
        $this->target              = $target;
        $this->batchSize           = $batchSize;
        $this->maxLoops            = $maxLoops;
        $this->simulateCrashAfter  = $simulateCrashAfter;
        $this->shardFilter         = $shardFilter;
        $this->totalShards         = $totalShards ?? Config::totalShards();
    }

    public function run() : int {
        $queue    = Queue_Manager::instance();
        $indexers = [
            'price'      => new PriceIndexer(),
            'stock'      => new StockIndexer(),
            'visibility' => new VisibilityIndexer(),
            'feed'       => new FeedIndexer(),
        ];
        $processed  = 0;
        $cronStatus = new CronStatus();
        for ( $loop = 0; $loop < $this->maxLoops; $loop++ ) {
            foreach ( $indexers as $key => $indexer ) {
                if ( 'all' !== $this->target && $this->target !== $key ) {
                    continue;
                }

                $requestedBatch = $this->determineBatchSize( $processed );
                if ( 0 === $requestedBatch ) {
                    return $processed;
                }

                $jobs = $queue->reserveBatch( $indexer->getChannel(), $requestedBatch, $this->shardFilter );
                if ( empty( $jobs ) ) {
                    continue;
                }
                $payloads = array_map( static fn( $job ) => $job->data, $jobs );
                try {
                    $indexer->processBatch( $payloads );
                    if ( $this->shouldSimulateCrash( $processed, count( $jobs ) ) ) {
                        $this->expireLeasesForJobs( $jobs );
                        throw new CrashSimulationException( sprintf( 'Simulated crash after %d jobs', (int) $this->simulateCrashAfter ) );
                    }
                    foreach ( $jobs as $job ) {
                        $queue->ack( $job );
                    }
                    $cronStatus->markRun( $key . '_worker' );
                    $processed += count( $jobs );
                } catch ( CrashSimulationException $simulation ) {
                    throw $simulation;
                } catch ( \Throwable $exception ) {
                    foreach ( $jobs as $job ) {
                        $queue->fail( $job, $exception->getMessage(), true );
                    }
                }
            }
        }
        return $processed;
    }

    /**
     * TEST-ONLY BEHAVIOR: force the simulated batch to look expired so the sweeper
     * can pick it up immediately. Never runs unless --simulate-crash-after is set.
     */
    private function expireLeasesForJobs( array $jobs ) : void {
        if ( null === $this->simulateCrashAfter || empty( $jobs ) ) {
            return;
        }
        global $wpdb;
        if ( ! $wpdb instanceof wpdb ) {
            return;
        }
        $ids = array_map( static fn( $job ) => $job->id, $jobs );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        $expiredAt = gmdate( 'Y-m-d H:i:s', time() - 5 );
        $updatedAt = current_time( 'mysql', true );
        $sql = "UPDATE {$wpdb->prefix}product_index_queue SET lease_expires_at = %s, updated_at = %s WHERE job_uuid IN (" . $placeholders . ")";
        $wpdb->query( $wpdb->prepare( $sql, ...array_merge( [ $expiredAt, $updatedAt ], $ids ) ) );
    }

    private function determineBatchSize( int $processed ) : int {
        if ( null === $this->simulateCrashAfter ) {
            return $this->batchSize;
        }
        $remaining = $this->simulateCrashAfter - $processed;
        if ( $remaining <= 0 ) {
            return 0;
        }
        return min( $this->batchSize, $remaining );
    }

    private function shouldSimulateCrash( int $processed, int $processedNow ) : bool {
        if ( null === $this->simulateCrashAfter ) {
            return false;
        }
        return ( $processed + $processedNow ) >= $this->simulateCrashAfter;
    }
}
