<?php
namespace APM;

use WP_CLI;
use WP_CLI_Command;

class CLI extends WP_CLI_Command {
    private Marketplace_Scheduler $scheduler;
    private Marketplace_Credentials $credentials;

    public function __construct( Marketplace_Scheduler $scheduler, Marketplace_Credentials $credentials ) {
        $this->scheduler    = $scheduler;
        $this->credentials  = $credentials;
    }

    /**
     * Forza una sincronizzazione marketplace.
     *
     * ## OPTIONS
     *
     * --account=<id>
     * : ID dell'account marketplace (vedi tab Repricer).
     *
     * --type=<import|publish>
     * : Tipo di job da eseguire.
     */
    public function sync( array $args, array $assoc_args ) : void {
        $account_id = isset( $assoc_args['account'] ) ? (int) $assoc_args['account'] : 0;
        $type       = $assoc_args['type'] ?? 'import';
        if ( ! $account_id || ! in_array( $type, [ 'import', 'publish' ], true ) ) {
            WP_CLI::error( 'Parametri non validi. Usa --account=ID --type=import|publish' );
        }
        $accounts = $this->credentials->all();
        $account  = null;
        foreach ( $accounts as $item ) {
            if ( (int) $item['id'] === $account_id ) {
                $account = $item;
                break;
            }
        }
        if ( ! $account ) {
            WP_CLI::error( 'Account non trovato.' );
        }
        $hook = 'import' === $type ? 'apm_marketplace_import_run' : 'apm_marketplace_publish_run';
        WP_CLI::log( sprintf( 'Eseguo %s per account %d (%s)…', $type, $account_id, $account['marketplace'] ) );
        do_action( $hook, $account_id, $account['marketplace'] );
        WP_CLI::success( 'Job completato.' );
    }
}
