<?php
namespace Aurora\Enterprise\Support;

use function get_option;

class Config {
    public static function redisConfig() : array {
        $host = defined( 'AURORA_REDIS_HOST' ) ? AURORA_REDIS_HOST : '127.0.0.1';
        $port = defined( 'AURORA_REDIS_PORT' ) ? (int) AURORA_REDIS_PORT : 6379;
        $password = defined( 'AURORA_REDIS_PASSWORD' ) ? AURORA_REDIS_PASSWORD : null;
        $database = defined( 'AURORA_REDIS_DB' ) ? (int) AURORA_REDIS_DB : 0;
        return compact( 'host', 'port', 'password', 'database' );
    }

    public static function snapshotV2Enabled() : bool {
        if ( defined( 'AURORA_SNAPSHOT_V2' ) ) {
            return (bool) AURORA_SNAPSHOT_V2;
        }
        $option = get_option( 'aurora_snapshot_v2_enabled', false );
        return (bool) $option;
    }

    public static function idempotenceTtlSeconds() : int {
        if ( defined( 'AURORA_IDEMPOTENCE_TTL' ) ) {
            return max( 60, (int) AURORA_IDEMPOTENCE_TTL );
        }
        $option = (int) get_option( 'aurora_idempotence_ttl', 900 );
        return max( 60, $option );
    }

    public static function leaseTtlSeconds() : int {
        if ( defined( 'AURORA_QUEUE_LEASE_TTL' ) ) {
            return max( 15, (int) AURORA_QUEUE_LEASE_TTL );
        }
        $option = (int) get_option( 'aurora_queue_lease_ttl', 60 );
        return max( 15, $option );
    }

    public static function leaseSweepCronEnabled() : bool {
        if ( defined( 'AURORA_LEASE_SWEEP_CRON_ENABLED' ) ) {
            return (bool) AURORA_LEASE_SWEEP_CRON_ENABLED;
        }
        $option = get_option( 'aurora_lease_sweep_cron_enabled', true );
        return (bool) $option;
    }

    public static function totalShards() : int {
        if ( defined( 'AURORA_TOTAL_SHARDS' ) ) {
            return max( 1, (int) AURORA_TOTAL_SHARDS );
        }
        $option = (int) get_option( 'aurora_total_shards', 8 );
        return max( 1, $option );
    }
}
