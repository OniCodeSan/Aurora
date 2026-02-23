<?php
namespace Aurora\Enterprise\Indexer;

use function wp_upload_dir;
use function wp_mkdir_p;
use function sanitize_file_name;
use function trailingslashit;
use function current_time;
use function wp_json_encode;
use function wp_generate_uuid4;
use function file_exists;
use function file_put_contents;
use function fseek;
use function ftell;
use function ftruncate;
use function fflush;
use Aurora\Enterprise\Support\Logger;
use wpdb;

class FeedIndexer extends AbstractIndexer {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function getChannel() : string {
        return 'feed';
    }

    /**
     * @param array<int,array<string,mixed>> $jobs
     */
    public function processBatch( array $jobs ) : void {
        if ( empty( $jobs ) ) {
            return;
        }
        $groups = [];
        foreach ( $jobs as $job ) {
            $feedId = sanitize_file_name( (string) ( $job['feed_id'] ?? wp_generate_uuid4() ) );
            $groups[ $feedId ][] = $job;
        }
        foreach ( $groups as $feedId => $feedJobs ) {
            $this->processFeedJobs( $feedId, $feedJobs );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $jobs
     */
    private function processFeedJobs( string $feedId, array $jobs ) : void {
        global $wpdb;
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'aurora-feeds/';
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \RuntimeException( 'Unable to create feed directory.' );
        }
        $tmpPath = $dir . $feedId . '.jsonl.tmp';
        $statePath = $dir . $feedId . '.state.json';
        $state = $this->loadState( $statePath );

        $handle = fopen( $tmpPath, 'c+' );
        if ( ! $handle ) {
            throw new \RuntimeException( sprintf( 'Unable to open feed file %s for writing.', $tmpPath ) );
        }
        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            throw new \RuntimeException( 'Unable to lock feed file.' );
        }
        fseek( $handle, 0, SEEK_END );
        $state = $this->recoverPartialChunks( $handle, $state, $statePath );
        foreach ( $jobs as $job ) {
            $chunk    = (int) ( $job['chunk'] ?? 1 );
            $total    = (int) ( $job['total_chunks'] ?? 1 );
            $version  = (int) ( $job['snapshot_cut_version'] ?? 0 );
            if ( $version <= 0 ) {
                fclose( $handle );
                throw new \RuntimeException( 'Missing snapshot_cut_version for feed job.' );
            }
            $state = $this->syncState( $statePath, $state, $total, $version );
            if ( isset( $state['received'][ $chunk ] ) ) {
                continue;
            }
            $startOffset = ftell( $handle );
            if ( false === $startOffset ) {
                fclose( $handle );
                throw new \RuntimeException( 'Unable to determine feed file offset.' );
            }
            if ( ! isset( $state['in_progress'] ) || ! is_array( $state['in_progress'] ) ) {
                $state['in_progress'] = [];
            }
            $state['in_progress'][ $chunk ] = $startOffset;
            $this->persistState( $statePath, $state );
            $productIds = array_map( 'intval', (array) ( $job['product_ids'] ?? [] ) );
            $written    = 0;
            foreach ( $productIds as $productId ) {
                $row = $this->buildSnapshotRow( $productId, $version );
                if ( empty( $row ) ) {
                    continue;
                }
                fwrite( $handle, wp_json_encode( $row ) . "\n" );
                $written++;
            }
            if ( isset( $state['in_progress'][ $chunk ] ) ) {
                unset( $state['in_progress'][ $chunk ] );
            }
            if ( ! isset( $state['row_count'] ) ) {
                $state['row_count'] = 0;
            }
            $state['row_count'] += $written;
            $state['received'][ $chunk ] = true;
            $this->persistState( $statePath, $state );
        }
        flock( $handle, LOCK_UN );
        fclose( $handle );

        $this->logger->info( 'feed', 'Processed feed chunk', [
            'feed_id' => $feedId,
            'received' => array_keys( $state['received'] ),
            'total'    => $state['total_chunks'],
        ] );
        if ( count( $state['received'] ) >= (int) $state['total_chunks'] ) {
            $finalPath = $dir . $feedId . '-' . gmdate( 'YmdHis' ) . '.jsonl';
            rename( $tmpPath, $finalPath );
            unlink( $statePath );
            $this->logger->info( 'feed', 'Feed file completed', [ 'feed_id' => $feedId, 'path' => $finalPath ] );
            $this->persistMetadata( $finalPath, $state );
        }
    }

    private function persistMetadata( string $finalPath, array $state ) : void {
        $rows = (int) ( $state['row_count'] ?? 0 );
        \Aurora\Enterprise\Ops\Feed_Metadata_Store::update( [
            'file_name'        => basename( $finalPath ),
            'rows'             => $rows,
            'snapshot_version' => (int) ( $state['snapshot_version'] ?? 0 ),
            'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'size_bytes'       => (int) filesize( $finalPath ),
        ] );
    }

    private function recoverPartialChunks( $handle, array $state, string $statePath ) : array {
        if ( empty( $state['in_progress'] ) || ! is_array( $state['in_progress'] ) ) {
            return $state;
        }
        $offsets = array_filter( $state['in_progress'], static function ( $value ) {
            return is_numeric( $value );
        } );
        if ( ! empty( $offsets ) ) {
            $target = min( array_map( 'intval', $offsets ) );
            ftruncate( $handle, $target );
            fflush( $handle );
        }
        fseek( $handle, 0, SEEK_END );
        $state['in_progress'] = [];
        $this->persistState( $statePath, $state );
        return $state;
    }

    private function persistState( string $statePath, array $state ) : void {
        file_put_contents( $statePath, wp_json_encode( $state ), LOCK_EX );
    }

    private function loadState( string $statePath ) : array {
        if ( ! file_exists( $statePath ) ) {
            return [ 'total_chunks' => 0, 'snapshot_version' => 0, 'received' => [], 'row_count' => 0, 'in_progress' => [] ];
        }
        $decoded = json_decode( file_get_contents( $statePath ), true );
        if ( ! is_array( $decoded ) ) {
            return [ 'total_chunks' => 0, 'snapshot_version' => 0, 'received' => [], 'row_count' => 0, 'in_progress' => [] ];
        }
        if ( ! isset( $decoded['received'] ) || ! is_array( $decoded['received'] ) ) {
            $decoded['received'] = [];
        }
        return $decoded;
    }

    private function syncState( string $statePath, array $state, int $total, int $version ) : array {
        if ( empty( $state['total_chunks'] ) ) {
            $state['total_chunks'] = $total;
        }
        if ( empty( $state['snapshot_version'] ) ) {
            $state['snapshot_version'] = $version;
        }
        if ( $state['total_chunks'] !== $total || $state['snapshot_version'] !== $version ) {
            throw new \RuntimeException( 'Feed manifest mismatch detected.' );
        }
        if ( ! isset( $state['row_count'] ) ) {
            $state['row_count'] = 0;
        }
        if ( ! isset( $state['in_progress'] ) || ! is_array( $state['in_progress'] ) ) {
            $state['in_progress'] = [];
        }
        return $state;
    }

    private function buildSnapshotRow( int $productId, int $version ) : array {
        global $wpdb;
        $price = $this->fetchRow( $wpdb->prefix . 'aurora_price_snapshot', $productId, $version );
        $stock = $this->fetchRow( $wpdb->prefix . 'aurora_stock_snapshot', $productId, $version );
        $visibility = $this->fetchRow( $wpdb->prefix . 'aurora_visibility_snapshot', $productId, $version );
        if ( ! $price && ! $stock && ! $visibility ) {
            return [];
        }
        return [
            'product_id' => $productId,
            'snapshot_version' => $version,
            'price'      => $price,
            'stock'      => $stock,
            'visibility' => $visibility,
            'exported_at'=> current_time( 'mysql', true ),
        ];
    }

    private function fetchRow( string $table, int $productId, int $version ) : ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE version = %d AND product_id = %d ORDER BY variation_id ASC LIMIT 1",
                $version,
                $productId
            ),
            ARRAY_A
        );
        return $row ?: null;
    }
}
