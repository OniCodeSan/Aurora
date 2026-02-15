<?php
namespace APM;

class Marketplace_Scheduler {
    private Marketplace_Credentials $credentials;
    private Logger $logger;

    private const IMPORT_HOOK  = 'apm_marketplace_import';
    private const PUBLISH_HOOK = 'apm_marketplace_publish';

    public function __construct( Marketplace_Credentials $credentials, Logger $logger ) {
        $this->credentials = $credentials;
        $this->logger      = $logger;
    }

    public function init() : void {
        add_action( 'admin_init', [ $this, 'maybe_schedule_all' ] );
        add_action( 'wp_loaded', [ $this, 'maybe_schedule_all' ] );

        add_action( self::IMPORT_HOOK, [ $this, 'handle_import_job' ], 10, 2 );
        add_action( self::PUBLISH_HOOK, [ $this, 'handle_publish_job' ], 10, 2 );

        add_action( 'apm_marketplace_account_saved', [ $this, 'ensure_account_jobs' ], 10, 2 );
        add_action( 'apm_marketplace_account_deleted', [ $this, 'clear_account_jobs' ], 10, 2 );
    }

    public function maybe_schedule_all() : void {
        if ( ! $this->action_scheduler_available() ) {
            return;
        }
        $accounts = $this->credentials->all();
        foreach ( $accounts as $account ) {
            $this->ensure_account_jobs( (int) $account['id'], $account['marketplace'] );
        }
    }

    public function ensure_account_jobs( int $account_id, string $marketplace ) : void {
        if ( ! $this->action_scheduler_available() ) {
            return;
        }
        $import_interval  = $this->get_interval_for( $marketplace, 'import' );
        $publish_interval = $this->get_interval_for( $marketplace, 'publish' );

        if ( $import_interval && ! as_next_scheduled_action( self::IMPORT_HOOK, [ $account_id, $marketplace ] ) ) {
            as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $import_interval, self::IMPORT_HOOK, [ $account_id, $marketplace ], 'apm_marketplace' );
        }
        if ( $publish_interval && ! as_next_scheduled_action( self::PUBLISH_HOOK, [ $account_id, $marketplace ] ) ) {
            as_schedule_recurring_action( time() + ( 2 * MINUTE_IN_SECONDS ), $publish_interval, self::PUBLISH_HOOK, [ $account_id, $marketplace ], 'apm_marketplace' );
        }
    }

    public function clear_account_jobs( int $account_id, string $marketplace ) : void {
        if ( ! $this->action_scheduler_available() ) {
            return;
        }
        as_unschedule_all_actions( self::IMPORT_HOOK, [ $account_id, $marketplace ], 'apm_marketplace' );
        as_unschedule_all_actions( self::PUBLISH_HOOK, [ $account_id, $marketplace ], 'apm_marketplace' );
    }

    public function handle_import_job( int $account_id, string $marketplace ) : void {
        $this->logger->debug( 'Marketplace import job queued', [ 'account_id' => $account_id, 'marketplace' => $marketplace ] );
        do_action( 'apm_marketplace_import_run', $account_id, $marketplace );
    }

    public function handle_publish_job( int $account_id, string $marketplace ) : void {
        $this->logger->debug( 'Marketplace publish job queued', [ 'account_id' => $account_id, 'marketplace' => $marketplace ] );
        do_action( 'apm_marketplace_publish_run', $account_id, $marketplace );
    }

    private function action_scheduler_available() : bool {
        return function_exists( 'as_schedule_recurring_action' ) && class_exists( '\ActionScheduler' );
    }

    private function get_interval_for( string $marketplace, string $type ) : int {
        $defaults = [
            'amazon' => [ 'import' => 1800, 'publish' => 600 ], // 30 min import, 10 min publish
            'ebay'   => [ 'import' => 2700, 'publish' => 900 ],  // 45 min import, 15 min publish
        ];
        if ( ! isset( $defaults[ $marketplace ][ $type ] ) ) {
            return 0;
        }
        return (int) $defaults[ $marketplace ][ $type ];
    }
}
