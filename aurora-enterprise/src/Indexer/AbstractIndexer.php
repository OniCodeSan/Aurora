<?php
namespace Aurora\Enterprise\Indexer;

abstract class AbstractIndexer {
    protected int $batchSize = 750;

    abstract public function getChannel() : string;

    /**
     * Full rebuild entry point.
     */
    public function fullRebuild() : void {
        // Placeholder: implement bulk rebuild pipeline.
    }

    /**
     * Process a batch from queue.
     *
     * @param array<int,array<string,mixed>> $jobs
     */
    abstract public function processBatch( array $jobs ) : void;
}
