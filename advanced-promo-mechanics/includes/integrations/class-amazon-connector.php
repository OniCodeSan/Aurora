<?php
namespace APM\Integrations;

use APM\Marketplace_Credentials;
use APM\Logger;

class Amazon_Connector {
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
        if ( 'amazon' !== $marketplace ) {
            return;
        }
        $account = $this->credentials->get( $account_id );
        if ( ! $account ) {
            $this->logger->error( 'Amazon import: account non trovato', [ 'account_id' => $account_id ] );
            return;
        }
        $this->logger->debug( 'Amazon import stub', [ 'account_id' => $account_id, 'seller_id' => $account['data']['seller_id'] ?? '' ] );
        do_action( 'apm_marketplace_import_completed', $account_id, 'amazon', [] );
    }

    public function handle_publish( int $account_id, string $marketplace ) : void {
        if ( 'amazon' !== $marketplace ) {
            return;
        }
        $account = $this->credentials->get( $account_id );
        if ( ! $account ) {
            $this->logger->error( 'Amazon publish: account non trovato', [ 'account_id' => $account_id ] );
            return;
        }
        $this->logger->debug( 'Amazon publish stub', [ 'account_id' => $account_id, 'seller_id' => $account['data']['seller_id'] ?? '' ] );
        do_action( 'apm_marketplace_publish_completed', $account_id, 'amazon', [] );
    }
}
