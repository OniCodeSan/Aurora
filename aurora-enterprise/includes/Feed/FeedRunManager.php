<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use Aurora\Enterprise\Ops\Ops_Run_Manager;
use Aurora\Enterprise\Support\Logger;
use Aurora\Enterprise\Support\SnapshotVersionManager;
use Exception;
use function wp_generate_uuid4;
use function as_enqueue_async_action;
use function as_has_scheduled_action;

class FeedRunManager {
    private const LOCK_TTL = 900;
    private const REFRESH_SECONDS = 30;
    private const MEMORY_THRESHOLD = 70; // percent
    private const MAX_SECONDS = 90;

    private FeedLockManager $lockManager;
    private FeedChunkProcessor $chunkProcessor;
    private FeedValidator $validator;
    private Logger $logger;
    private Ops_Run_Manager $runs;
    private SnapshotVersionManager $versions;

    public function __construct(
        FeedLockManager $lockManager,
        FeedChunkProcessor $chunkProcessor,
        FeedValidator $validator
    ) {
        $this->lockManager = $lockManager;
        $this->chunkProcessor = $chunkProcessor;
        $this->validator = $validator;
        $this->logger = new Logger();
        $this->runs = Ops_Run_Manager::instance();
        $this->versions = new SnapshotVersionManager();
    }

    public function start(int $runId): array {
        $owner = wp_generate_uuid4();
        $progress = $this->ensureProgressRow($runId);

        // Acquire lock first; if busy, do not transition run state.
        if (! $this->lockManager->acquire($owner, self::LOCK_TTL)) {
            $this->runs->mark_partial($runId, ['message' => 'lock busy']);
            return ['run_id' => $runId, 'status' => 'partial', 'reason' => 'lock busy'];
        }

        $snapshotVersion = $this->detectSnapshotVersion();
        $writer = new FeedJsonlWriter($runId);
        $writer->open((int)$progress['file_part'], true);

        $this->transitionToRunning($runId, $progress, $owner, $snapshotVersion);

        $lastId = (int)$progress['last_product_id'];
        $rowsWritten = (int)$progress['rows_written'];
        $bytesWritten = (int)$progress['bytes_written'];
        $part = (int)$progress['file_part'];
        $started = microtime(true);
        $lastRefresh = time();

        $runStart = microtime(true);

        try {
            while (true) {
                if ($this->timedOut($started) || $this->memoryExceeded()) {
                    $this->persistPartial($runId, $owner, $snapshotVersion, $part, $rowsWritten, $bytesWritten, $lastId);
                    $this->scheduleResume($runId);
                    return ['run_id' => $runId, 'status' => 'partial'];
                }

                $chunk = $this->chunkProcessor->fetchChunk($snapshotVersion, $lastId, $this->chunkProcessor->getBatchSize());
                if (empty($chunk['rows'])) {
                    $finalPath = $writer->finalizeCurrentPart();
                    $files = $this->listParts($runId, $part, $finalPath);
                    $meta = $this->finalizeMeta($runId, $snapshotVersion, $rowsWritten, $bytesWritten, $files, $runStart);
                    return ['run_id' => $runId, 'status' => 'completed', 'rows' => $rowsWritten, 'files' => $files];
                }

                foreach ($chunk['rows'] as $row) {
                    $writer->writeLine(wp_json_encode($row));
                    $rowsWritten++;
                }
                $bytesWritten = $writer->getWrittenBytes();
                $lastId = (int)$chunk['last_product_id'];

                if ($writer->maybeRotate()) {
                    $writer->close();
                    $writer->open(++$part, false);
                }

                $this->persistProgress($runId, [
                    'status' => 'running',
                    'owner' => $owner,
                    'snapshot_version' => $snapshotVersion,
                    'file_part' => $part,
                    'rows_written' => $rowsWritten,
                    'bytes_written' => $bytesWritten,
                    'last_product_id' => $lastId,
                    'updated_at' => current_time('mysql', true),
                ]);

                if (time() - $lastRefresh >= self::REFRESH_SECONDS) {
                    $this->lockManager->refresh($owner, self::LOCK_TTL);
                    $lastRefresh = time();
                }
            }
        } catch (\Throwable $exception) {
            $this->runs->mark_error($runId, $exception->getMessage());
            $this->persistProgress($runId, [
                'status' => 'failed',
                'owner' => $owner,
                'error' => $exception->getMessage(),
                'updated_at' => current_time('mysql', true),
            ]);
            throw $exception;
        } finally {
            $this->lockManager->release($owner);
            $writer->close();
        }
    }

    private function finalizeMeta(int $runId, int $snapshotVersion, int $rows, int $bytes, array $files, float $runStart): array {
        $duration = microtime(true) - $runStart;
        $parts = count($files);
        $expected = $this->validator->expectedCount($snapshotVersion);
        $result = $this->validator->validate($expected, $rows);
        $this->validator->logResult($result['ok'] ? 'info' : 'error', $runId, $snapshotVersion, $result, $files, $bytes);

        $this->runs->mark_success($runId, [
            'message' => 'feed completed',
            'rows' => $rows,
            'bytes' => $bytes,
            'parts' => $parts,
            'duration' => $duration,
        ]);

        update_option('aurora_last_feed_meta', wp_json_encode([
            'run_id' => $runId,
            'files' => $files,
            'parts' => $parts,
            'rows' => $rows,
            'bytes_total' => $bytes,
            'snapshot_version' => $snapshotVersion,
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
        ]), false);

        $this->persistProgress($runId, [
            'status' => 'completed',
            'rows_written' => $rows,
            'bytes_written' => $bytes,
            'last_product_id' => $result['ok'] ? $runId : $runId, // conservative placeholder
            'updated_at' => current_time('mysql', true),
        ]);

        $this->logInfo('completed', [
            'run_id' => $runId,
            'rows' => $rows,
            'bytes' => $bytes,
            'parts' => $parts,
            'expected' => $expected,
            'ratio' => $result['ratio'],
            'duration' => $duration,
        ]);

        return $result;
    }

    private function detectSnapshotVersion(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_price_snapshot';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (empty($exists)) {
            return 0;
        }
        return $this->versions->currentVersion($table);
    }

    private function ensureProgressRow(int $runId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_feed_progress';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE run_id = %d", $runId), ARRAY_A
        );
        if ($row) {
            return $row;
        }
        $now = current_time('mysql', true);
        $wpdb->insert($table, [
            'run_id' => $runId,
            'status' => 'pending',
            'file_part' => 1,
            'rows_written' => 0,
            'bytes_written' => 0,
            'last_product_id' => 0,
            'started_at' => $now,
            'updated_at' => $now,
        ]);
        return [
            'run_id' => $runId,
            'status' => 'pending',
            'owner' => null,
            'snapshot_version' => null,
            'file_part' => 1,
            'rows_written' => 0,
            'bytes_written' => 0,
            'last_product_id' => 0,
            'started_at' => $now,
            'updated_at' => $now,
            'error' => null,
        ];
    }

    private function persistProgress(int $runId, array $fields): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_feed_progress';
        $wpdb->update($table, $fields, ['run_id' => $runId]);
    }

    private function transitionToRunning(int $runId, array $progress, string $owner, int $snapshotVersion): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_ops_runs';
        $now = current_time('mysql', true);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='running', started_at=%s, updated_at=%s WHERE id=%d AND status='requested'",
            $now, $now, $runId
        ));
        if ((int)$updated !== 1 && ($progress['status'] ?? '') !== 'running') {
            throw new Exception('Unable to transition run to running');
        }
        $this->persistProgress($runId, [
            'status' => 'running',
            'owner' => $owner,
            'snapshot_version' => $snapshotVersion,
            'started_at' => $progress['started_at'] ?? $now,
            'updated_at' => $now,
        ]);
    }

    private function timedOut(float $started): bool {
        return (microtime(true) - $started) >= self::MAX_SECONDS;
    }

    private function memoryExceeded(): bool {
        $limit = (int) ini_get('memory_limit');
        if ($limit <= 0) {
            return false; // conservative fallback
        }
        $usage = memory_get_usage(true);
        return ($usage / $limit * 100) >= self::MEMORY_THRESHOLD;
    }

    private function persistPartial(int $runId, string $owner, int $snapshotVersion, int $part, int $rows, int $bytes, int $lastId): void {
        $this->runs->mark_partial($runId, ['message' => 'partial']);
        $this->persistProgress($runId, [
            'status' => 'partial',
            'owner' => $owner,
            'snapshot_version' => $snapshotVersion,
            'file_part' => $part,
            'rows_written' => $rows,
            'bytes_written' => $bytes,
            'last_product_id' => $lastId,
            'updated_at' => current_time('mysql', true),
        ]);
        $this->logInfo('partial', [
            'run_id' => $runId,
            'rows' => $rows,
            'bytes' => $bytes,
            'last_product_id' => $lastId,
            'part' => $part,
        ]);
    }

    private function scheduleResume(int $runId): void {
        if (! function_exists('as_enqueue_async_action')) {
            return;
        }
        $args = [ [ 'run_id' => $runId, 'op_key' => 'feed_run', 'payload' => [ 'run_id' => $runId ] ] ];
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('aurora_ops_dispatch', $args, 'aurora')) {
            return;
        }
        as_enqueue_async_action('aurora_ops_dispatch', $args, 'aurora');
    }

    private function listParts(int $runId, int $lastPart, string $lastFinal): array {
        $dir = dirname($lastFinal) . '/';
        $files = [];
        for ($p = 1; $p <= $lastPart; $p++) {
            $candidate = $dir . sprintf('feed_run_%dpart%d.jsonl', $runId, $p);
            if (file_exists($candidate)) {
                $files[] = $candidate;
            }
        }
        return $files;
    }

    private function logInfo(string $event, array $context): void {
        $path = $this->logPath('feed.log');
        $line = sprintf('%s [%s] %s', gmdate('c'), $event, wp_json_encode($context));
        file_put_contents($path, $line . "\n", FILE_APPEND);
    }

    private function logError(string $event, array $context): void {
        $path = $this->logPath('feed-error.log');
        $line = sprintf('%s [%s] %s', gmdate('c'), $event, wp_json_encode($context));
        file_put_contents($path, $line . "\n", FILE_APPEND);
    }

    private function logPath(string $file): string {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'aurora-feeds/';
        wp_mkdir_p($dir);
        return $dir . $file;
    }
}
