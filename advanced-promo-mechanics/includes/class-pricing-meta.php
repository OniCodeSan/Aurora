<?php
namespace APM;

class Pricing_Meta {
    public function init() : void {
        add_action( 'woocommerce_product_options_pricing', [ $this, 'render_simple_fields' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_simple_fields' ], 10, 2 );

        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_fields' ], 10, 2 );

        add_filter( 'woocommerce_product_data_tabs', [ $this, 'register_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_tab_panel' ] );
    }

    public function register_tab( array $tabs ) : array {
        $tabs['apm_pricing'] = [
            'label'    => __( 'Aurora Pricing', 'advanced-promo-mechanics' ),
            'target'   => 'apm_pricing_data',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 70,
        ];
        return $tabs;
    }

    public function render_tab_panel() : void {
        global $post;
        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }
        $cost        = get_post_meta( $post->ID, '_apm_cost', true );
        $min_margin  = get_post_meta( $post->ID, '_apm_min_margin', true );
        $strategy    = get_post_meta( $post->ID, '_apm_pricing_strategy', true );
        ?>
        <div id="apm_pricing_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input( [
                    'id'          => '_apm_cost',
                    'value'       => $cost,
                    'label'       => __( 'Costo unitario', 'advanced-promo-mechanics' ),
                    'desc_tip'    => true,
                    'description' => __( 'Costo netto per unità (usato per il calcolo del margine).', 'advanced-promo-mechanics' ),
                    'type'        => 'number',
                    'custom_attributes' => [
                        'step' => '0.01',
                        'min'  => '0',
                    ],
                ] );

                woocommerce_wp_text_input( [
                    'id'          => '_apm_min_margin',
                    'value'       => $min_margin,
                    'label'       => __( 'Margine minimo (%)', 'advanced-promo-mechanics' ),
                    'desc_tip'    => true,
                    'description' => __( 'Percentuale di margine lordo minimo desiderato (es. 25 per il 25%).', 'advanced-promo-mechanics' ),
                    'type'        => 'number',
                    'custom_attributes' => [
                        'step' => '0.1',
                        'min'  => '0',
                        'max'  => '100',
                    ],
                ] );

                woocommerce_wp_select( [
                    'id'      => '_apm_pricing_strategy',
                    'value'   => $strategy ?: 'margin_guard',
                    'label'   => __( 'Strategia di repricing', 'advanced-promo-mechanics' ),
                    'options' => [
                        'margin_guard' => __( 'Protect Margine', 'advanced-promo-mechanics' ),
                        'stock_push'   => __( 'Smaltisci stock lento', 'advanced-promo-mechanics' ),
                        'custom'       => __( 'Custom (API/external)', 'advanced-promo-mechanics' ),
                    ],
                ] );
                ?>
            </div>
            <p class="description"><?php esc_html_e( 'Questi valori alimentano il Repricer Aurora e vengono replicati nelle snapshot multi-tenant.', 'advanced-promo-mechanics' ); ?></p>
        </div>
        <?php
    }

    public function render_simple_fields() : void {
        echo '<div class="options_group show_if_simple">';
        $this->render_shared_inline_fields( get_the_ID() );
        echo '</div>';
    }

    public function render_variation_fields( $loop, $variation_data, $variation ) : void {
        $cost       = get_post_meta( $variation->ID, '_apm_cost', true );
        $min_margin = get_post_meta( $variation->ID, '_apm_min_margin', true );
        ?>
        <div class="form-row form-row-full">
            <?php
            woocommerce_wp_text_input( [
                'id'                => "_apm_cost_{$loop}",
                'name'              => "_apm_cost[{$variation->ID}]",
                'value'             => $cost,
                'label'             => __( 'Costo unitario', 'advanced-promo-mechanics' ),
                'type'              => 'number',
                'wrapper_class'     => 'form-row-first',
                'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
            ] );

            woocommerce_wp_text_input( [
                'id'                => "_apm_min_margin_{$loop}",
                'name'              => "_apm_min_margin[{$variation->ID}]",
                'value'             => $min_margin,
                'label'             => __( 'Margine minimo (%)', 'advanced-promo-mechanics' ),
                'type'              => 'number',
                'wrapper_class'     => 'form-row-last',
                'custom_attributes' => [ 'step' => '0.1', 'min' => '0', 'max' => '100' ],
            ] );
            ?>
        </div>
        <?php
    }

    private function render_shared_inline_fields( int $post_id ) : void {
        $cost       = get_post_meta( $post_id, '_apm_cost', true );
        $min_margin = get_post_meta( $post_id, '_apm_min_margin', true );

        woocommerce_wp_text_input( [
            'id'                => '_apm_cost_inline',
            'name'              => '_apm_cost',
            'value'             => $cost,
            'label'             => __( 'Costo unitario', 'advanced-promo-mechanics' ),
            'type'              => 'number',
            'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_apm_min_margin_inline',
            'name'              => '_apm_min_margin',
            'value'             => $min_margin,
            'label'             => __( 'Margine minimo (%)', 'advanced-promo-mechanics' ),
            'type'              => 'number',
            'custom_attributes' => [ 'step' => '0.1', 'min' => '0', 'max' => '100' ],
        ] );
    }

    public function save_simple_fields( int $post_id ) : void {
        if ( isset( $_POST['_apm_cost'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, '_apm_cost', $this->sanitize_decimal( wp_unslash( $_POST['_apm_cost'] ) ) );
        }
        if ( isset( $_POST['_apm_min_margin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, '_apm_min_margin', $this->sanitize_decimal( wp_unslash( $_POST['_apm_min_margin'] ) ) );
        }
        if ( isset( $_POST['_apm_pricing_strategy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $post_id, '_apm_pricing_strategy', sanitize_text_field( wp_unslash( $_POST['_apm_pricing_strategy'] ) ) );
        }
    }

    public function save_variation_fields( int $variation_id ) : void {
        if ( isset( $_POST['_apm_cost'][ $variation_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $variation_id, '_apm_cost', $this->sanitize_decimal( wp_unslash( $_POST['_apm_cost'][ $variation_id ] ) ) );
        }
        if ( isset( $_POST['_apm_min_margin'][ $variation_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            update_post_meta( $variation_id, '_apm_min_margin', $this->sanitize_decimal( wp_unslash( $_POST['_apm_min_margin'][ $variation_id ] ) ) );
        }
    }

    private function sanitize_decimal( $value ) : ?string {
        $value = is_array( $value ) ? reset( $value ) : $value;
        $value = wc_clean( $value );
        if ( '' === $value ) {
            return null;
        }
        return wc_format_decimal( $value );
    }
}
