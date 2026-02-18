<?php
namespace APM;

class Feed_Exporter {
    private Feed_Logs $logs;

    public function __construct( Feed_Logs $logs ) {
        $this->logs = $logs;
    }

    /**
     * Genera un feed per il profilo dato e restituisce dettagli sull'operazione.
     *
     * @param array<string,mixed> $profile
     * @return array{success:bool,message:string,file_path?:string,file_url?:string,products?:int}
     */
    public function generate_for_profile( array $profile ) : array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return $this->failure( $profile, __( 'WooCommerce non disponibile.', 'advanced-promo-mechanics' ) );
        }

        apm_ensure_feed_dir();
        $paths = apm_get_feed_paths();
        $extension = ( isset( $profile['format'] ) && 'csv' === $profile['format'] ) ? 'csv' : 'xml';
        $slug = $this->determine_slug( $profile );
        $file_path = trailingslashit( $paths['dir'] ) . $slug . '.' . $extension;
        $file_url  = trailingslashit( $paths['url'] ) . $slug . '.' . $extension;

        $products = $this->collect_products();
        if ( empty( $products ) ) {
            return $this->failure( $profile, __( 'Nessun prodotto pubblicato da esportare.', 'advanced-promo-mechanics' ) );
        }

        $written = 'csv' === $extension
            ? $this->write_csv( $products, $file_path )
            : $this->write_xml( $products, $file_path );

        if ( ! $written ) {
            return $this->failure( $profile, __( 'Impossibile scrivere il file feed (controlla i permessi).', 'advanced-promo-mechanics' ) );
        }

        $count   = count( $products );
        $message = sprintf( __( 'Feed aggiornato (%d prodotti).', 'advanced-promo-mechanics' ), $count );
        $profile_id = (int) ( $profile['id'] ?? 0 );
        $this->logs->record( $profile_id, 'success', $message, $file_path );
        apm_log_activity( 'feed_generated', $message, [ 'profile_id' => $profile_id, 'file' => $file_path, 'url' => $file_url, 'products' => $count ] );

        return [
            'success'   => true,
            'message'   => $message,
            'file_path' => $file_path,
            'file_url'  => $file_url,
            'products'  => $count,
        ];
    }

    /**
     * @return array<int,array<string,string|int|float|null>>
     */
    private function collect_products() : array {
        $ids = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return [];
        }

        $products = [];
        foreach ( $ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $products[] = [
                'id'                 => $product->get_id(),
                'sku'                => $product->get_sku(),
                'name'               => $product->get_name(),
                'description'        => wp_strip_all_tags( $product->get_description() ),
                'short_description'  => wp_strip_all_tags( $product->get_short_description() ),
                'regular_price'      => $product->get_regular_price(),
                'sale_price'         => $product->get_sale_price(),
                'stock_status'       => $product->get_stock_status(),
                'stock_quantity'     => $product->managing_stock() ? $product->get_stock_quantity() : null,
                'permalink'          => get_permalink( $product_id ),
                'image'              => $this->get_product_image( $product ),
            ];
        }

        return $products;
    }

    private function write_xml( array $products, string $file_path ) : bool {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent( true );
        $writer->startDocument( '1.0', 'UTF-8' );
        $writer->startElement( 'products' );
        $writer->writeAttribute( 'generated', gmdate( 'c' ) );

        foreach ( $products as $product ) {
            $writer->startElement( 'product' );
            foreach ( $product as $key => $value ) {
                if ( null === $value || '' === $value ) {
                    continue;
                }
                $writer->writeElement( $key, (string) $value );
            }
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return false !== file_put_contents( $file_path, $writer->outputMemory() );
    }

    private function write_csv( array $products, string $file_path ) : bool {
        $handle = fopen( $file_path, 'w' );
        if ( false === $handle ) {
            return false;
        }

        $headers = [ 'id', 'sku', 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'permalink', 'image' ];
        fputcsv( $handle, $headers, ';' );
        foreach ( $products as $product ) {
            $row = [];
            foreach ( $headers as $column ) {
                $row[] = isset( $product[ $column ] ) ? $product[ $column ] : '';
            }
            fputcsv( $handle, $row, ';' );
        }

        fclose( $handle );
        return true;
    }

    private function get_product_image( \WC_Product $product ) : ?string {
        $image_id = $product->get_image_id();
        if ( ! $image_id ) {
            return null;
        }
        $image_url = wp_get_attachment_url( $image_id );
        return $image_url ?: null;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function determine_slug( array $profile ) : string {
        $name = isset( $profile['name'] ) ? sanitize_title( $profile['name'] ) : '';
        if ( '' === $name ) {
            $name = 'feed-profile';
            $id   = isset( $profile['id'] ) ? (int) $profile['id'] : 0;
            if ( $id > 0 ) {
                $name .= '-' . $id;
            }
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{success:false,message:string}
     */
    private function failure( array $profile, string $message ) : array {
        $profile_id = (int) ( $profile['id'] ?? 0 );
        $this->logs->record( $profile_id, 'error', $message );
        apm_log_activity( 'feed_error', $message, [ 'profile_id' => $profile_id ] );
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
