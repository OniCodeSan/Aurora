<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use RuntimeException;

class FeedJsonlWriter {
    private const DEFAULT_MAX_BYTES = 52428800; // 50MB
    private const DEFAULT_FLUSH_EVERY = 100;

    private int $runId;
    private int $maxBytes;
    private int $flushEvery;
    private string $dir;
    private int $part = 1;
    private int $writtenBytes = 0;
    private int $writtenRows = 0;
    private int $flushCounter = 0;
    private $handle = null;
    private string $currentTmp = '';

    public function __construct(int $runId, int $maxBytes = self::DEFAULT_MAX_BYTES, int $flushEvery = self::DEFAULT_FLUSH_EVERY) {
        $this->runId = $runId;
        $this->maxBytes = $maxBytes;
        $this->flushEvery = $flushEvery;
        $upload = wp_upload_dir();
        $this->dir = trailingslashit($upload['basedir']) . 'aurora-feeds/';
        if ( ! wp_mkdir_p($this->dir) ) {
            throw new RuntimeException('Unable to create feed directory: ' . $this->dir);
        }
    }

    public function open(int $part, bool $append = true): void {
        $this->part = $part;
        $this->writtenBytes = 0;
        $this->writtenRows = 0;
        $this->flushCounter = 0;
        $this->currentTmp = $this->tmpPath($part);
        $mode = $append ? 'a' : 'w';
        $this->handle = fopen($this->currentTmp, $mode);
        if ( ! $this->handle ) {
            throw new RuntimeException('Unable to open feed tmp file: ' . $this->currentTmp);
        }
        if ($append) {
            $stat = fstat($this->handle);
            if ( $stat && isset($stat['size']) ) {
                $this->writtenBytes = (int) $stat['size'];
            }
        }
    }

    public function writeLine(string $jsonLine): void {
        if (null === $this->handle) {
            throw new RuntimeException('Writer not opened');
        }
        $bytes = fwrite($this->handle, $jsonLine . "\n");
        if (false === $bytes) {
            throw new RuntimeException('Failed writing feed line');
        }
        $this->writtenBytes += $bytes;
        $this->writtenRows++;
        $this->flushCounter++;
        if ($this->flushCounter >= $this->flushEvery) {
            fflush($this->handle);
            $this->flushCounter = 0;
        }
    }

    public function maybeRotate(): bool {
        if ($this->writtenBytes < $this->maxBytes) {
            return false;
        }
        $this->finalizeCurrentPart();
        $this->open($this->part + 1, false);
        return true;
    }

    public function getCurrentPart(): int {
        return $this->part;
    }

    public function getCurrentTmpPath(): string {
        return $this->currentTmp;
    }

    public function getWrittenBytes(): int {
        return $this->writtenBytes;
    }

    public function getWrittenRows(): int {
        return $this->writtenRows;
    }

    public function close(): void {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function finalizeCurrentPart(): string {
        $this->close();
        $final = $this->finalPath($this->part);
        if (file_exists($final)) {
            // Conservative: if final exists we assume it was already finalized; bump part.
            $this->part++;
            return $final;
        }
        if ( ! rename($this->currentTmp, $final) ) {
            throw new RuntimeException('Unable to finalize feed part: ' . $this->currentTmp);
        }
        return $final;
    }

    private function tmpPath(int $part): string {
        return $this->dir . sprintf('feed_run_%dpart%d.jsonl.tmp', $this->runId, $part);
    }

    private function finalPath(int $part): string {
        return $this->dir . sprintf('feed_run_%dpart%d.jsonl', $this->runId, $part);
    }
}
