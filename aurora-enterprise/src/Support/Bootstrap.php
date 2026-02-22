<?php
namespace Aurora\Enterprise\Support;

use function error_log;

use Aurora\Enterprise\Admin\Dashboard;
use Aurora\Enterprise\CLI\Enqueue_Command;
use Aurora\Enterprise\CLI\Worker_Command;
use Aurora\Enterprise\CLI\Rebuild_Command;
use Aurora\Enterprise\CLI\Status_Command;
use Aurora\Enterprise\CLI\Feed_Command;
use Aurora\Enterprise\CLI\Migrate_Command;
use Aurora\Enterprise\CLI\Queue_Sweep_Command;
use Aurora\Enterprise\CLI\Queue_Retry_Command;
use Aurora\Enterprise\CLI\Test_Command;
use Aurora\Enterprise\CLI\Queue_Backfill_Shards_Command;
use Aurora\Enterprise\CLI\Upgrade_Command;
use Aurora\Enterprise\Events\Product_Event_Subscriber;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Queue\DatabaseQueue;
use Aurora\Enterprise\Queue\RedisQueue;
use Aurora\Enterprise\Http\Controllers\Dashboard_Controller;
use Aurora\Enterprise\Http\Controllers\Queue_Controller;
use Aurora\Enterprise\Http\Controllers\Metrics_Controller;
use Aurora\Enterprise\Support\Config;
use Aurora\Enterprise\Support\CronStatus;

class Bootstrap {
    private const SWEEPER_CRON_HOOK = 'aurora_queue_sweeper_run';
    public function init() : void {
        $this->maybe_warn_wp_cron();
        $this->register_admin();
        $this->register_events();
        $this->register_rest();
        $this->register_cli_commands();
        $this->register_queue_sweeper_cron();
        add_action( 'aurora_rebuild_async', [ $this, 'handle_async_rebuild' ], 10, 1 );
    }

    private function maybe_warn_wp_cron() : void {
        if ( wp_doing_cron() ) {
            return;
        }
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return;
        }
        add_action( 'admin_notices', static function () {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Aurora Enterprise: disattiva WP-Cron e usa cron di sistema per performance ottimali.', 'aurora-enterprise' ) . '</p></div>';
        } );
    }

    private function register_admin() : void {
        ( new Dashboard() )->hooks();
    }

    private function register_events() : void {
        ( new Product_Event_Subscriber() )->hooks();
    }

    private function register_rest() : void {
        add_action( 'rest_api_init', static function () {
            ( new Dashboard_Controller() )->register_routes();
            ( new \Aurora\Enterprise\Http\Controllers\Cron_Controller() )->register_routes();
            ( new Queue_Controller() )->register_routes();
            ( new Metrics_Controller() )->register_routes();
        } );
    }

    private function register_cli_commands() : void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'aurora enqueue', new Enqueue_Command() );
            \WP_CLI::add_command( 'aurora worker', new Worker_Command() );
            \WP_CLI::add_command( 'aurora rebuild', new Rebuild_Command() );
            \WP_CLI::add_command( 'aurora queue status', new Status_Command() );
            \WP_CLI::add_command( 'aurora queue sweep-leases', new Queue_Sweep_Command() );
            \WP_CLI::add_command( 'aurora queue retry-dead', new Queue_Retry_Command() );
            \WP_CLI::add_command( 'aurora queue backfill-shards', new Queue_Backfill_Shards_Command() );
            \WP_CLI::add_command( 'aurora test', new Test_Command() );
            $migrateCommand = new Migrate_Command();
            \WP_CLI::add_command( 'aurora migrate', $migrateCommand );
            \WP_CLI::add_command( 'aurora migrate snapshot-v2', [ $migrateCommand, 'snapshot_v2' ] );
            \WP_CLI::add_command( 'aurora feed', new Feed_Command() );
            \WP_CLI::add_command( 'aurora upgrade', new Upgrade_Command() );
        }
    }

    private function register_queue_sweeper_cron() : void {
        add_filter( 'cron_schedules', static function ( array $schedules ) : array {
            if ( isset( $schedules['aurora_five_minutes'] ) ) {
                return $schedules;
            }
            $schedules['aurora_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Aurora lease sweeper (5min)', 'aurora-enterprise' ),
            ];
            return $schedules;
        } );

        add_action( self::SWEEPER_CRON_HOOK, [ $this, 'run_queue_sweeper' ] );

        if ( Config::leaseSweepCronEnabled() ) {
            if ( ! wp_next_scheduled( self::SWEEPER_CRON_HOOK ) ) {
                wp_schedule_event( time(), 'aurora_five_minutes', self::SWEEPER_CRON_HOOK );
            }
        } else {
            wp_clear_scheduled_hook( self::SWEEPER_CRON_HOOK );
        }
    }

    public function run_queue_sweeper() : void {
        if ( ! Config::leaseSweepCronEnabled() ) {
            return;
        }
        $queue = Queue_Manager::instance()->driver();
        if ( $queue instanceof RedisQueue ) {
            error_log( '[Aurora] Sweeper skipped: driver=redis' );
            return;
        }
        if ( ! $queue instanceof DatabaseQueue ) {
            return;
        }
                $ttl = Config::leaseTtlSeconds();
        $totalShards = Config::totalShards();
        for ( $shard = 0; $shard < $totalShards; $shard++ ) {
            $queue->sweepExpiredLeases( null, $ttl, $shard );
        }
    }

    public function handle_async_rebuild( string $target = 'all' ) : void {
        $indexers = [
            'price'      => new \Aurora\Enterprise\Indexer\PriceIndexer(),
            'stock'      => new \Aurora\Enterprise\Indexer\StockIndexer(),
            'visibility' => new \Aurora\Enterprise\Indexer\VisibilityIndexer(),
        ];
        foreach ( $indexers as $key => $service ) {
            if ( 'all' !== $target && $key !== $target ) {
                continue;
            }
            $service->fullRebuild();
            update_option( 'aurora_last_rebuild_' . $key, current_time( 'mysql', true ), false );
        }
    }
}
