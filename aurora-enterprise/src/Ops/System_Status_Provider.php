<?php
namespace Aurora\Enterprise\Ops;

class System_Status_Provider {
    private Ops_Run_Manager $runs;
    private const CACHE_KEY = 'aurora_system_status_v1';
    private const CACHE_TTL = 30;

    public function __construct() {
        $this->runs = Ops_Run_Manager::instance();
    }

    public function get_status() : array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $queues = $this->queue_stats();
        $snap   = $this->snapshot_status();
        $feed   = $this->last_feed();
        $runs   = $this->runs->recent_runs( 20 );

        [ $healthStatus, $reasons ] = $this->health_rules( $queues, $snap );

        $data = [
            'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'health'   => [
                'status'  => $healthStatus,
                'reasons' => $reasons,
            ],
            'config'   => [
                'queue_driver'    => defined( 'AURORA_QUEUE_DRIVER' ) ? AURORA_QUEUE_DRIVER : 'database',
                'total_shards'    => (int) get_option( 'aurora_total_shards', 8 ),
                'lease_ttl'       => (int) get_option( 'aurora_queue_lease_ttl', 60 ),
                'idempotence_ttl' => (int) get_option( 'aurora_idempotence_ttl', 900 ),
                'snapshot_v2'     => (bool) get_option( 'aurora_snapshot_v2_enabled', false ),
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ],
            'queues'   => $queues,
            'snapshots'=> $snap,
            'feed'     => $feed,
            'last_runs'=> $runs,
        ];

        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }

    private function queue_stats() : array {
        $stats = \Aurora\Enterprise\Queue\Queue_Manager::instance()->stats();
        return [
            'price'      => (int) ( $stats['price'] ?? 0 ),
            'stock'      => (int) ( $stats['stock'] ?? 0 ),
            'visibility' => (int) ( $stats['visibility'] ?? 0 ),
            'feed'       => (int) ( $stats['feed'] ?? 0 ),
            'dead'       => (int) ( $stats['dead'] ?? 0 ),
        ];
    }

    private function snapshot_status() : array {
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_snapshot_versions';
        $versions = [];
        $rows = $wpdb->get_results( "SELECT channel, current_version FROM {$table}", ARRAY_A );
        foreach ( $rows as $row ) {
            $versions[ $row['channel'] ] = (int) $row['current_version'];
        }
        $aligned = count( array_unique( $versions ) ) <= 1;

        $coverage = [];
        foreach ( $versions as $channel => $version ) {
            $snapTable = $wpdb->prefix . "aurora_{$channel}_snapshot";
            $cov = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS c, COUNT(DISTINCT product_id) AS d FROM {$snapTable} WHERE version = %d",
                    $version
                ),
                ARRAY_A
            );
            $coverage[ $channel ] = [
                'version'  => $version,
                'count'    => (int) ( $cov['c'] ?? 0 ),
                'distinct' => (int) ( $cov['d'] ?? 0 ),
            ];
        }

        return [
            'versions' => $versions,
            'aligned'  => $aligned,
            'coverage' => $coverage,
        ];
    }

    private function last_feed() : array {
        $meta = get_option( 'aurora_last_feed_meta', [] );
        return is_array( $meta ) ? $meta : [];
    }

    private function health_rules( array $queues, array $snap ) : array {
        $reasons = [];
        $dead = (int) ( $queues['dead'] ?? 0 );
        if ( $dead > 0 ) {
            $reasons[] = 'dead=' . $dead;
        }

        foreach ( [ 'price', 'stock', 'visibility' ] as $ch ) {
            $pending = (int) ( $queues[ $ch ] ?? 0 );
            if ( $pending > 10000 ) {
                $reasons[] = "backlog {$ch}={$pending}";
            }
        }

        if ( ! ( $snap['aligned'] ?? true ) ) {
            $reasons[] = 'snapshot_versions_misaligned';
        }

        $status = 'HEALTHY';
        if ( ! empty( $reasons ) ) {
            $status = 'WARNING';
        }
        if ( $dead > 0 ) {
            $status = 'ERROR';
        }

        return [ $status, $reasons ];
    }
}
