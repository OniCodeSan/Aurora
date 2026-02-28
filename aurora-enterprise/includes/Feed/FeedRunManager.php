<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Support\Logger;
use Aurora\Enterprise\Queue\Queue_Manager;
use Exception;

class FeedRunManager {
    private FeedLockManager $lockManager;
    private FeedChunkProcessor $chunkProcessor;
    private FeedValidator $validator;
    private Logger $logger;
    private Ops_Run_Manager $runs;
    private int $maxRunSeconds;
    private int $memoryThreshold;

    public function __construct(
        FeedLockManager $lockManager,
        FeedChunkProcessor $chunkProcessor,
        FeedValidator $validator,
        int $maxRunSeconds = 90,
        int $memoryThreshold = 70
    ) {
        $this->lockManager = $lockManager;
        $this->chunkProcessor = $chunkProcessor;
        $this->validator = $validator;
        $this->logger = new Logger();
        $this->runs = Ops_Run_Manager::instance();
        $this->maxRunSeconds = $maxRunSeconds;
        $this->memoryThreshold = $memoryThreshold;
    }

    public function start(string $feedId, array $payload): array {
        $runId = $this->runs->create_run($feedId, 'feed_run', null, $payload);
        if ($runId <= 0) {
            throw new Exception('Unable to create feed run');
        }

        if (!$this->lockManager->acquire_lock($runId)) {
            throw new Exception('Feed run already locked');
        }

        $start = time();
        $processed = 0;
        $lastId = 0;
        $offset = 0;

        try {
            while (true) {
                $this->guard_timeout($start);
                $this->guard_memory();

        $rows = $this->chunkProcessor->read_products($payload['snapshot_version'], $lastId, $this->chunkProcessor->getBatchSize());
        if (empty($rows)) {
            break;
        }

        $processed += $this->process_chunk($rows);
        $lastId = (int)end($rows)['product_id'];
                $this->chunkProcessor->update_checkpoint('feed', (int)($payload['shard'] ?? 0), $lastId);
                $this->persist_progress($runId, $lastId, $offset, $processed);
            }

            $this->finalize($runId, $processed, $feedId);
            return ['run_id' => $runId, 'processed' => $processed];
        } catch (Exception $exception) {
            $this->runs->mark_error($runId, $exception->getMessage());
            throw $exception;
        } finally {
            $this->lockManager->release_lock($runId);
        }
    }

    private function process_chunk(array $jobs): int {
        $queue = Queue_Manager::instance();
        foreach ($jobs as $job) {
            $queue->ack(new Payload($job));
        }
        return count($jobs);
    }

    private function persist_progress(int $runId, int $lastProcessedId, int $offset, int $total): void {
        $this->runs->update($runId, [
            'meta_json' => json_encode([
                'last_processed_id' => $lastProcessedId,
                'offset' => $offset,
                'total_processed' => $total,
            ]),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    private function finalize(int $runId, int $processed, string $feedId): void {
        $this->runs->mark_success($runId, [
            'processed' => $processed,
            'feed_id' => $feedId,
        ]);
    }

    private function guard_timeout(int $start): void {
        if ((time() - $start) >= $this->maxRunSeconds) {
            throw new Exception('Feed run timed out');
        }
    }

    private function guard_memory(): void {
        $limit = (int)ini_get('memory_limit');
        $usage = memory_get_usage(true);
        if ($limit > 0 && ($usage / $limit * 100) >= $this->memoryThreshold) {
            throw new Exception('Memory threshold reached');
        }
    }
}
