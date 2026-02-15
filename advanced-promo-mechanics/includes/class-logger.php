<?php
namespace APM;

use WC_Logger;
use WC_Log_Levels;

class Logger {
    private WC_Logger $logger;

    public function __construct() {
        $this->logger = new WC_Logger();
    }

    public function debug( string $message, array $context = [] ) : void {
        $settings = get_option( 'apm_settings', [] );
        if ( empty( $settings['debug'] ) ) {
            return;
        }
        $this->logger->log( WC_Log_Levels::DEBUG, wp_json_encode( [ 'message' => $message, 'context' => $context ], JSON_UNESCAPED_SLASHES ), [ 'source' => 'advanced-promo-mechanics' ] );
    }

    public function error( string $message, array $context = [] ) : void {
        $this->logger->log( WC_Log_Levels::ERROR, wp_json_encode( [ 'message' => $message, 'context' => $context ], JSON_UNESCAPED_SLASHES ), [ 'source' => 'advanced-promo-mechanics' ] );
    }
}
