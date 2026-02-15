<?php
namespace Aurora\Enterprise\Support;

class Config {
    public static function redisConfig() : array {
        $host = defined( 'AURORA_REDIS_HOST' ) ? AURORA_REDIS_HOST : '127.0.0.1';
        $port = defined( 'AURORA_REDIS_PORT' ) ? (int) AURORA_REDIS_PORT : 6379;
        $password = defined( 'AURORA_REDIS_PASSWORD' ) ? AURORA_REDIS_PASSWORD : null;
        $database = defined( 'AURORA_REDIS_DB' ) ? (int) AURORA_REDIS_DB : 0;
        return compact( 'host', 'port', 'password', 'database' );
    }
}
