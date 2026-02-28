<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

class FeedWriter {
    private const MAX_BYTES = 50 * 1024 * 1024;
    private const FLUSH_INTERVAL = 100;

    private string $directory;
    private string $feedId;
    private string $currentPath;
    private int $part;
    private int $rows;
    private int $bytes;
    private int $flushCounter;
    private $handle;

    public function __construct(string $feedId) {
        $this->feedId = $feedId;
        $upload = wp_upload_dir();
        $this->directory = trailingslashit($upload['basedir']) . 'aurora-feeds/';
        wp_mkdir_p($this->directory);
        $this->rows = 0;
        $this->bytes = 0;
        $this->part = 1;
        $this->open_writer();
    }

    public function write(array $data): void {
        $line = wp_json_encode($data) . "\n";
        if (false === $line) {
            throw new \RuntimeException('Unable to encode feed row.');
        }
        $bytes = fwrite($this->handle, $line);
        if (false === $bytes) {
            throw new \RuntimeException('Unable to write feed row.');
        }
        $this->rows++;
        $this->bytes += $bytes;
        $this->flushCounter++;
        if ($this->flushCounter >= self::FLUSH_INTERVAL) {
            fflush($this->handle);
            $this->flushCounter = 0;
        }
        if ($this->bytes >= self::MAX_BYTES) {
            $this->rotate();
        }
    }

    public function get_state(): array {
        return [
            'feed_id' => $this->feedId,
            'file_part' => $this->part,
            'rows_written' => $this->rows,
            'bytes_written' => $this->bytes,
            'current_file' => $this->currentPath,
        ];
    }

    public function close(): void {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function rotate(): void {
        $this->close();
        $this->part++;
        $this->rows = 0;
        $this->bytes = 0;
        $this->open_writer();
    }

    private function open_writer(): void {
        $path = $this->directory . sprintf('%s_%d.jsonl', $this->feedId, $this->part);
        $this->currentPath = $path;
        $this->handle = fopen($path, 'a');
        if (!$this->handle) {
            throw new \RuntimeException('Unable to open feed file for writing.');
        }
    }
}
