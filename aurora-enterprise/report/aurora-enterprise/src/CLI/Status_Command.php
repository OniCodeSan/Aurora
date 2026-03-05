<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;

class Status_Command extends WP_CLI_Command {
    /**
     * Show queue statistics.
     */
    public function __invoke() : void {
        $stats = Queue_Manager::instance()->stats();
        WP_CLI::line( 'Aurora Queue Status:' );
        foreach ( $stats as $key => $value ) {
            WP_CLI::line( sprintf( ' - %s: %s', $key, $value ) );
        }
    }
}
