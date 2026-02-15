<?php
namespace APM\Integrations;

use APM\Marketplace_Credentials;
use APM\Logger;

class Ebay_Connector {
    private Marketplace_Credentials $credentials;
    private Logger $logger;

    public function __construct( Marketplace_Credentials $credentials, Logger $logger ) {
        $this->credentials = $credentials;
        $this->logger      = $logger;
    }

    public function init() : void {
        add_action( 'apm_marketplace_import_run', [ $this, 'handle_import' ], 10, 2 );
        add_action( 'apm_marketplace_publish_run', [ $this, 'handle_publish' ], 10, 2 );
    }

    public function handle_import( int $account_id, string $marketplace ) : void {
        if ( 'ebay' !== $marketplace ) {
            return;
        }
        $account = $this->credentials->get( $account_id );
        if ( ! $account ) {
            $this->logger->error( 'eBay import: account non trovato', [ 'account_id' => $account_id ] );
            return;
        }
        $this->logger->debug( 'eBay import stub', [ 'account_id' => $account_id, 'ru_name' => $account['data']['ru_name'] ?? '' ] );
        do_action( 'apm_marketplace_import_completed', $account_id, 'ebay', [] );
    }

    public function handle_publish( int $account_id, string $marketplace ) : void {
        if ( 'ebay' !== $marketplace ) {
            return;
        }
        $account = $this->credentials->get( $account_id );
        if ( ! $account ) {
            $this->logger->error( 'eBay publish: account non trovato', [ 'account_id' => $account_id ] );
            return;
        }
        $this->logger->debug( 'eBay publish stub', [ 'account_id' => $account_id, 'ru_name' => $account['data']['ru_name'] ?? '' ] );
        do_action( 'apm_marketplace_publish_completed', $account_id, 'ebay', [] );
    }
}
