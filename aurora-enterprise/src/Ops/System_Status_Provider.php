<?php
namespace Aurora\Enterprise\Ops;

use Aurora\Enterprise\Ops\Ops_Run_Manager;

class System_Status_Provider {
    private Ops_Run_Manager $runs;
    private const CACHE_KEY = 'aurora_system_status';
    private const CACHE_TTL = 30;

    public function __construct() {
        $this->runs = Ops_Run_Manager::instance();
    }

    public function get_status() : array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $data = [
            'health'   => [
                'status'  => 'healthy',
                'reasons' => [],
            ],
            'config'   => [
                'queue_driver'    => defined( 'AURORA_QUEUE_DRIVER' ) ? AURORA_QUEUE_DRIVER : 'database',
                'total_shards'    => (int) get_option( 'aurora_total_shards', 8 ),
                'lease_ttl'       => (int) get_option( 'aurora_queue_lease_ttl', 60 ),
                'idempotence_ttl' => (int) get_option( 'aurora_idempotence_ttl', 900 ),
                'snapshot_v2'     => (bool) get_option( 'aurora_snapshot_v2_enabled', false ),
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            ],
            'queues'   => [
                'price'      => [ 'pending' => 0, 'processing' => 0, 'dead' => 0 ],
                'stock'      => [ 'pending' => 0, 'processing' => 0, 'dead' => 0 ],
                'visibility' => [ 'pending' => 0, 'processing' => 0, 'dead' => 0 ],
                'feed'       => [ 'pending' => 0, 'processing' => 0, 'dead' => 0 ],
                'dead'       => 0,
            ],
            'snapshots' => [
                'price'      => [ 'current_version' => null, 'count' => null, 'distinct' => null, 'aligned' => true ],
                'stock'      => [ 'current_version' => null, 'count' => null, 'distinct' => null, 'aligned' => true ],
                'visibility' => [ 'current_version' => null, 'count' => null, 'distinct' => null, 'aligned' => true ],
            ],
            'feed'     => [
                'last_file' => null,
                'queue'     => 0,
            ],
            'last_runs' => $this->runs->recent_runs(),
        ];
        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }
}
