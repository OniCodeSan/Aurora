<?php
namespace Aurora\Enterprise\Support;

use Aurora\Enterprise\Admin\Dashboard;
use Aurora\Enterprise\CLI\Enqueue_Command;
use Aurora\Enterprise\CLI\Worker_Command;
use Aurora\Enterprise\CLI\Rebuild_Command;
use Aurora\Enterprise\CLI\Status_Command;
use Aurora\Enterprise\CLI\Feed_Command;
use Aurora\Enterprise\Events\Product_Event_Subscriber;
use Aurora\Enterprise\Http\Controllers\Dashboard_Controller;
use Aurora\Enterprise\Support\CronStatus;

class Bootstrap {
    public function init() : void {
        $this->maybe_warn_wp_cron();
        $this->register_admin();
        $this->register_events();
        $this->register_rest();
        $this->register_cli_commands();
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
        } );
    }

    private function register_cli_commands() : void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'aurora enqueue', new Enqueue_Command() );
            \WP_CLI::add_command( 'aurora worker', new Worker_Command() );
            \WP_CLI::add_command( 'aurora rebuild', new Rebuild_Command() );
            \WP_CLI::add_command( 'aurora queue status', new Status_Command() );
            \WP_CLI::add_command( 'aurora feed', new Feed_Command() );
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
