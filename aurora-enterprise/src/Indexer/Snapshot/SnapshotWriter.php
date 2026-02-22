<?php
namespace Aurora\Enterprise\Indexer\Snapshot;

use RuntimeException;
use wpdb;

use function array_chunk;
use function current_time;
use function pack;
use function strtolower;
use function wp_json_encode;

class SnapshotWriter {
    private const TABLE_MAP = [
        'price'      => 'aurora_price_snapshot',
        'stock'      => 'aurora_stock_snapshot',
        'visibility' => 'aurora_visibility_snapshot',
    ];

    private wpdb $db;
    private string $channel;
    private string $tableName;

    public function __construct( string $channel ) {
        if ( ! isset( self::TABLE_MAP[ $channel ] ) ) {
            throw new RuntimeException( 'Unsupported snapshot channel: ' . $channel );
        }
        global $wpdb;
        $this->db        = $wpdb;
        $this->channel   = $channel;
        $this->tableName = $wpdb->prefix . self::TABLE_MAP[ $channel ];
    }

    public function getTableName() : string {
        return $this->tableName;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function persist( array $rows, int $version ) : int {
        if ( empty( $rows ) ) {
            return 0;
        }
        return $this->insertRows( $rows, $version );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function insertRows( array $rows, int $version ) : int {
        $now    = current_time( 'mysql', true );
        $count  = 0;
        $chunks = array_chunk( $rows, 200 );
        foreach ( $chunks as $chunk ) {
            $this->db->query( 'START TRANSACTION' );
            try {
                foreach ( $chunk as $row ) {
                    $payload = $this->normalizeRow( $row, $version, $now );
                    $this->db->replace( $this->tableName, $payload );
                    $count++;
                }
                $this->db->query( 'COMMIT' );
            } catch ( \Throwable $exception ) {
                $this->db->query( 'ROLLBACK' );
                throw $exception;
            }
        }
        return $count;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow( array $row, int $version, string $timestamp ) : array {
        $scopeRegion  = strtolower( (string) ( $row['scope_region'] ?? 'default' ) );
        $scopeChannel = strtolower( (string) ( $row['scope_channel'] ?? 'default' ) );
        $payload      = [
            'product_id'    => (int) $row['product_id'],
            'scope_region'  => $scopeRegion,
            'scope_channel' => $scopeChannel,
            'version'       => $version,
            'variation_id'  => isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0,
            'sku'           => (string) ( $row['sku'] ?? '' ),
            'updated_at'    => $timestamp,
        ];

        if ( isset( $row['currency'] ) ) {
            $payload['currency']        = (string) $row['currency'];
            $payload['regular_price']   = $this->maybeFloat( $row['regular_price'] ?? null );
            $payload['sale_price']      = $this->maybeFloat( $row['sale_price'] ?? null );
            $payload['effective_price'] = $this->maybeFloat( $row['effective_price'] ?? null );
            $payload['margin_percent']  = $this->maybeFloat( $row['margin_percent'] ?? null );
        }

        if ( isset( $row['stock_qty'] ) || isset( $row['stock_status'] ) ) {
            $payload['stock_qty']    = isset( $row['stock_qty'] ) ? (int) $row['stock_qty'] : null;
            $payload['stock_status'] = (string) ( $row['stock_status'] ?? 'instock' );
            $payload['warehouse']    = $row['warehouse'] ?? null;
        }

        if ( isset( $row['visibility'] ) ) {
            $payload['visibility']    = (string) $row['visibility'];
            $payload['catalog_flags'] = $row['catalog_flags'] ?? null;
            $payload['channel_mask']  = isset( $row['channel_mask'] ) ? (int) $row['channel_mask'] : 0;
        }

        $payload['hash'] = $this->hashRow( $payload );
        return $payload;
    }

    private function maybeFloat( $value ) : ?float {
        if ( null === $value || '' === $value ) {
            return null;
        }
        return (float) $value;
    }

    private function hashRow( array $payload ) : string {
        $encoded = wp_json_encode( $payload ) ?: '';
        return pack( 'H*', md5( $encoded ) );
    }
}
