<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceRuleEngine {
    private RepricePriceEngine $priceEngine;
    private wpdb $db;
    private ?string $brandTaxonomy = null;

    public function __construct( ?RepricePriceEngine $priceEngine = null ) {
        $this->priceEngine = $priceEngine ?? new RepricePriceEngine();
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $context
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>|null
     */
    public function evaluate_rules_for_product( array $rules, array $context, array $defaultConfig = [] ) : ?array {
        if ( empty( $rules ) ) {
            return null;
        }
        $selected = null;
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $decision = $this->evaluate_rule_for_product( $rule, $context, $defaultConfig );
            if ( ! is_array( $decision ) ) {
                continue;
            }
            $selected = $decision;
            $exclusive = (bool) ( $decision['rule_exclusive'] ?? false );
            if ( $exclusive ) {
                break;
            }
        }
        return $selected;
    }

    /**
     * @param array<string,mixed> $ruleRow
     * @param array<string,mixed> $context
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>|null
     */
    public function evaluate_rule_for_product( array $ruleRow, array $context, array $defaultConfig = [] ) : ?array {
        $rule = is_array( $ruleRow['rule_json'] ?? null ) ? $ruleRow['rule_json'] : $ruleRow;
        $meta = is_array( $rule['rule_meta'] ?? null ) ? $rule['rule_meta'] : [];
        $enabled = ! isset( $meta['enabled'] ) || (bool) $meta['enabled'];
        if ( ! $enabled ) {
            return null;
        }
        if ( ! $this->is_valid_now( is_array( $rule['validity'] ?? null ) ? $rule['validity'] : [] ) ) {
            return null;
        }

        $scope = is_array( $rule['scope'] ?? null ) ? $rule['scope'] : [];
        if ( ! $this->scope_matches( $scope, $context ) ) {
            return null;
        }
        $conditions = is_array( $rule['conditions'] ?? null ) ? $rule['conditions'] : [];
        if ( ! $this->conditions_match( $conditions, $context ) ) {
            return null;
        }
        $inventory = is_array( $rule['inventory_rules'] ?? null ) ? $rule['inventory_rules'] : [];
        if ( ! $this->inventory_matches( $inventory, $context ) ) {
            return null;
        }

        $strategy = is_array( $rule['pricing_strategy'] ?? null ) ? $rule['pricing_strategy'] : [];
        $guardrails = is_array( $rule['guardrails'] ?? null ) ? $rule['guardrails'] : [];

        $oldPrice = $this->float_or_null( $context['old_price'] ?? null );
        $cost = $this->float_or_null( $context['cost'] ?? null );
        if ( null === $oldPrice || $oldPrice <= 0 ) {
            return null;
        }

        $engineConfig = $this->build_engine_config( $strategy, $guardrails, $context, $defaultConfig );
        $input = [
            'old_price'        => $oldPrice,
            'cost'             => $cost,
            'override'         => ( ! empty( $context['override'] ) ) ? '1' : '0',
            'competitor_price' => $this->float_or_null( $context['competitor_price'] ?? null ),
            'min_price'        => $this->float_or_null( $context['min_price'] ?? null ),
            'max_price'        => $this->float_or_null( $context['max_price'] ?? null ),
            'map_price'        => $this->float_or_null( $context['map_price'] ?? null ),
        ];

        $engine = $this->priceEngine->evaluate( $input, $engineConfig );
        $ruleId = isset( $ruleRow['id'] ) ? (int) $ruleRow['id'] : 0;
        $ruleName = sanitize_text_field( (string) ( $meta['name'] ?? ( $ruleRow['name'] ?? '' ) ) );
        $strategyType = sanitize_text_field( (string) ( $strategy['type'] ?? 'manual' ) );
        $engine['price_rule_id'] = $ruleId > 0 ? $ruleId : null;
        $engine['price_rule_name'] = $ruleName;
        $engine['strategy_type'] = $strategyType;
        $engine['strategy_rule_id'] = (string) ( $ruleId > 0 ? $ruleId : ( $engine['strategy_rule_id'] ?? '' ) );
        $engine['rule_exclusive'] = isset( $meta['exclusive'] ) && (bool) $meta['exclusive'];
        if ( is_string( $engine['audit_json'] ?? null ) && '' !== $engine['audit_json'] ) {
            $audit = json_decode( $engine['audit_json'], true );
            if ( is_array( $audit ) ) {
                $audit['price_rule_id'] = $engine['price_rule_id'];
                $audit['price_rule_name'] = $ruleName;
                $audit['strategy_type'] = $strategyType;
                $engine['audit_json'] = wp_json_encode( $audit );
            }
        }
        return $engine;
    }

    /**
     * Resolve preview scope for a saved rule.
     *
     * @param array<string,mixed> $scope
     * @return array{resolved_count:int,sample_ids:array<int>,warnings:array<int,string>}
     */
    public function resolve_scope_products( array $scope, int $sampleLimit = 200 ) : array {
        $sampleLimit = max( 1, min( 500, $sampleLimit ) );
        $warnings = [];
        $resultIds = null;

        $productIds = $this->sanitize_int_array( $scope['product_ids'] ?? [] );
        if ( ! empty( $productIds ) ) {
            $resultIds = $productIds;
        }

        $categoryIds = $this->sanitize_int_array( $scope['category_ids'] ?? [] );
        if ( ! empty( $categoryIds ) ) {
            $catIds = $this->query_taxonomy_products( 'product_cat', $categoryIds );
            $resultIds = $this->merge_intersection( $resultIds, $catIds );
        }

        $brandIds = $this->sanitize_int_array( $scope['brand_ids'] ?? [] );
        $brandTerms = is_array( $scope['brand_terms'] ?? null ) ? $scope['brand_terms'] : [];
        $brandIds = array_values( array_unique( array_merge( $brandIds, $this->resolve_brand_terms_to_ids( $brandTerms ) ) ) );
        if ( ! empty( $brandIds ) ) {
            $brandTax = $this->brand_taxonomy();
            if ( null === $brandTax ) {
                $warnings[] = 'brand_taxonomy_non_disponibile';
            } else {
                $brandProducts = $this->query_taxonomy_products( $brandTax, $brandIds );
                $resultIds = $this->merge_intersection( $resultIds, $brandProducts );
            }
        }

        $supplierIds = $this->sanitize_text_array( $scope['supplier_ids'] ?? [] );
        if ( ! empty( $supplierIds ) ) {
            $supplierProducts = $this->query_postmeta_values( '_aurora_supplier_id', $supplierIds );
            $resultIds = $this->merge_intersection( $resultIds, $supplierProducts );
        }

        $productTypes = $this->sanitize_text_array( $scope['product_type'] ?? [] );
        if ( ! empty( $productTypes ) ) {
            $ptypeProducts = $this->query_postmeta_values( '_aurora_product_type', $productTypes );
            $resultIds = $this->merge_intersection( $resultIds, $ptypeProducts );
        }

        $lines = $this->sanitize_text_array( $scope['line'] ?? [] );
        if ( ! empty( $lines ) ) {
            $lineProducts = $this->query_postmeta_values( '_aurora_line', $lines );
            $resultIds = $this->merge_intersection( $resultIds, $lineProducts );
        }

        $erpCondition = sanitize_text_field( (string) ( $scope['erp_stock_condition'] ?? 'any' ) );
        if ( in_array( $erpCondition, [ 'eq_0', 'gt_0' ], true ) ) {
            $erpProducts = $this->query_stock_condition_products( $erpCondition );
            $resultIds = $this->merge_intersection( $resultIds, $erpProducts );
        }

        if ( ! empty( $scope['urgent_only'] ) ) {
            $urgentProducts = $this->query_postmeta_values( '_aurora_urgent', [ '1' ] );
            $resultIds = $this->merge_intersection( $resultIds, $urgentProducts );
        }

        if ( null === $resultIds ) {
            $warnings[] = 'scope_vuoto';
            $resultIds = [];
        }

        sort( $resultIds, SORT_NUMERIC );
        return [
            'resolved_count' => count( $resultIds ),
            'sample_ids'     => array_slice( $resultIds, 0, $sampleLimit ),
            'warnings'       => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $guardrails
     * @param array<string,mixed> $context
     * @param array<string,mixed> $defaultConfig
     * @return array<string,mixed>
     */
    private function build_engine_config( array $strategy, array $guardrails, array $context, array $defaultConfig ) : array {
        $config = $defaultConfig;
        $type = sanitize_text_field( (string) ( $strategy['type'] ?? 'manual' ) );

        $config['margin_mode'] = sanitize_text_field( (string) ( $guardrails['margin_mode'] ?? ( $defaultConfig['margin_mode'] ?? 'clamp' ) ) );
        $config['min_margin_percent'] = $this->float_or_default( $guardrails['min_margin_percent'] ?? null, (float) ( $defaultConfig['min_margin_percent'] ?? 0.0 ) );
        $config['min_margin_abs'] = $this->float_or_default( $guardrails['min_margin_abs'] ?? null, (float) ( $defaultConfig['min_margin_abs'] ?? 0.0 ) );
        $config['min_price'] = $this->float_or_null( $guardrails['min_price'] ?? null );
        $config['max_price'] = $this->float_or_null( $guardrails['max_price'] ?? null );
        $config['max_raise_pct'] = $this->float_or_default( $guardrails['max_raise_percent'] ?? null, (float) ( $defaultConfig['max_raise_pct'] ?? 0.0 ) );
        $config['max_drop_pct'] = $this->float_or_default( $guardrails['max_drop_percent'] ?? null, (float) ( $defaultConfig['max_drop_pct'] ?? 0.0 ) );

        $rounding = strtolower( sanitize_text_field( (string) ( $guardrails['rounding'] ?? 'none' ) ) );
        $config['rounding_mode'] = match ( $rounding ) {
            'x.99' => '.99',
            'x.49' => '.49',
            default => in_array( $rounding, [ 'none', 'step', '.99', '.49', '99', '49' ], true ) ? $rounding : 'none',
        };
        $config['rounding_step'] = $this->float_or_default( $guardrails['step_value'] ?? null, 0.0 );

        $oldPrice = $this->float_or_null( $context['old_price'] ?? null );
        $cost = $this->float_or_null( $context['cost'] ?? null );
        $competitor = $this->float_or_null( $context['competitor_price'] ?? null );

        switch ( $type ) {
            case 'markup':
                $markupPct = $this->float_or_default( $strategy['markup_percent'] ?? null, 0.0 );
                $markupAbs = $this->float_or_default( $strategy['markup_abs'] ?? null, 0.0 );
                $candidate = null;
                if ( null !== $oldPrice ) {
                    $candidate = $oldPrice + ( $oldPrice * ( $markupPct / 100.0 ) ) + $markupAbs;
                }
                $config['strategy'] = 'markup';
                $config['candidate_price_override'] = $candidate;
                $config['strategy_reason_code'] = 'strategy_markup';
                break;
            case 'margin':
                $target = $this->float_or_null( $strategy['margin_target_percent'] ?? null );
                $config['strategy'] = 'margin';
                if ( null !== $target ) {
                    $config['target_margin_percent'] = $target;
                }
                break;
            case 'manual':
                $manualMode = sanitize_text_field( (string) ( $strategy['manual_mode'] ?? 'keep' ) );
                $manualPrice = $this->float_or_null( $strategy['manual_price'] ?? null );
                $config['strategy'] = 'manual';
                if ( 'override' === $manualMode && null !== $manualPrice && $manualPrice > 0 ) {
                    $config['candidate_price_override'] = $manualPrice;
                    $config['strategy_reason_code'] = 'strategy_manual_override';
                } else {
                    $config['candidate_price_override'] = $oldPrice;
                    $config['strategy_reason_code'] = 'strategy_manual_keep';
                }
                break;
            case 'competitor':
                $mode = sanitize_text_field( (string) ( $strategy['competitor_mode'] ?? 'match' ) );
                $delta = $this->float_or_default( $strategy['competitor_delta'] ?? null, 0.0 );
                $config['strategy'] = 'beat' === $mode ? 'beat_competitor' : 'match_competitor';
                $config['competitor_price'] = $competitor;
                $config['beat_delta_abs'] = max( 0.0, $delta );
                break;
            default:
                $config['strategy'] = 'manual';
                $config['candidate_price_override'] = $oldPrice;
                $config['strategy_reason_code'] = 'strategy_manual_keep';
                break;
        }

        if ( null !== $cost ) {
            $config['cost'] = $cost;
        }
        return $config;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $context
     */
    private function scope_matches( array $scope, array $context ) : bool {
        $productId = (int) ( $context['product_id'] ?? 0 );
        if ( $productId <= 0 ) {
            return false;
        }
        $scopeProductIds = $this->sanitize_int_array( $scope['product_ids'] ?? [] );
        if ( ! empty( $scopeProductIds ) && ! in_array( $productId, $scopeProductIds, true ) ) {
            return false;
        }
        $scopeCategoryIds = $this->sanitize_int_array( $scope['category_ids'] ?? [] );
        if ( ! empty( $scopeCategoryIds ) ) {
            $ctxCats = $this->sanitize_int_array( $context['category_ids'] ?? [] );
            if ( empty( array_intersect( $scopeCategoryIds, $ctxCats ) ) ) {
                return false;
            }
        }
        $scopeBrandIds = $this->sanitize_int_array( $scope['brand_ids'] ?? [] );
        $scopeBrandTermValues = $this->sanitize_text_array( $scope['brand_terms'] ?? [] );
        $scopeBrandIds = array_values( array_unique( array_merge( $scopeBrandIds, $this->resolve_brand_terms_to_ids( $scopeBrandTermValues ) ) ) );
        if ( ! empty( $scopeBrandIds ) ) {
            $ctxBrandIds = $this->sanitize_int_array( $context['brand_ids'] ?? [] );
            if ( empty( array_intersect( $scopeBrandIds, $ctxBrandIds ) ) ) {
                return false;
            }
        }
        $scopeSupplier = $this->sanitize_text_array( $scope['supplier_ids'] ?? [] );
        if ( ! empty( $scopeSupplier ) ) {
            $ctxSupplier = (string) ( $context['supplier_id'] ?? '' );
            if ( '' === $ctxSupplier || ! in_array( $ctxSupplier, $scopeSupplier, true ) ) {
                return false;
            }
        }
        $scopePType = $this->sanitize_text_array( $scope['product_type'] ?? [] );
        if ( ! empty( $scopePType ) ) {
            $ctxPType = (string) ( $context['product_type'] ?? '' );
            if ( '' === $ctxPType || ! in_array( $ctxPType, $scopePType, true ) ) {
                return false;
            }
        }
        $scopeLine = $this->sanitize_text_array( $scope['line'] ?? [] );
        if ( ! empty( $scopeLine ) ) {
            $ctxLine = (string) ( $context['line'] ?? '' );
            if ( '' === $ctxLine || ! in_array( $ctxLine, $scopeLine, true ) ) {
                return false;
            }
        }

        $erp = sanitize_text_field( (string) ( $scope['erp_stock_condition'] ?? 'any' ) );
        if ( in_array( $erp, [ 'eq_0', 'gt_0' ], true ) ) {
            $stock = $this->float_or_default( $context['stock_qty'] ?? null, 0.0 );
            if ( 'eq_0' === $erp && $stock != 0.0 ) {
                return false;
            }
            if ( 'gt_0' === $erp && $stock <= 0.0 ) {
                return false;
            }
        }
        if ( ! empty( $scope['urgent_only'] ) && empty( $context['urgent_only'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $context
     */
    private function conditions_match( array $conditions, array $context ) : bool {
        $cost = $this->float_or_null( $context['cost'] ?? null );
        $costMin = $this->float_or_null( $conditions['cost_min'] ?? null );
        $costMax = $this->float_or_null( $conditions['cost_max'] ?? null );
        if ( null !== $costMin && ( null === $cost || $cost < $costMin ) ) {
            return false;
        }
        if ( null !== $costMax && ( null === $cost || $cost > $costMax ) ) {
            return false;
        }
        $cPos = (int) ( $context['competitor_position'] ?? 0 );
        $cMin = isset( $conditions['competitor_position_min'] ) ? (int) $conditions['competitor_position_min'] : null;
        $cMax = isset( $conditions['competitor_position_max'] ) ? (int) $conditions['competitor_position_max'] : null;
        if ( null !== $cMin && $cPos < $cMin ) {
            return false;
        }
        if ( null !== $cMax && $cPos > $cMax ) {
            return false;
        }
        $minReviews = isset( $conditions['min_reviews'] ) ? (int) $conditions['min_reviews'] : null;
        if ( null !== $minReviews && (int) ( $context['reviews_count'] ?? 0 ) < $minReviews ) {
            return false;
        }
        if ( ! $this->operator_condition_match( $conditions['rotation_index'] ?? null, $context['rotation_index'] ?? null ) ) {
            return false;
        }
        if ( ! $this->operator_condition_match( $conditions['sold_last_30_days'] ?? null, $context['sold_last_30_days'] ?? null ) ) {
            return false;
        }
        if ( ! empty( $conditions['top_search_only'] ) && empty( $context['top_search_only'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $inventory
     * @param array<string,mixed> $context
     */
    private function inventory_matches( array $inventory, array $context ) : bool {
        $stock = (int) $this->float_or_default( $context['stock_qty'] ?? null, 0.0 );
        $applyIfStockGt = isset( $inventory['apply_if_stock_gt'] ) ? (int) $inventory['apply_if_stock_gt'] : null;
        if ( null !== $applyIfStockGt && $stock <= $applyIfStockGt ) {
            return false;
        }
        return true;
    }

    /**
     * @param mixed $condition
     * @param mixed $actual
     */
    private function operator_condition_match( $condition, $actual ) : bool {
        if ( ! is_array( $condition ) || empty( $condition ) ) {
            return true;
        }
        $operator = sanitize_text_field( (string) ( $condition['operator'] ?? '' ) );
        $value = $this->float_or_null( $condition['value'] ?? null );
        $actualValue = $this->float_or_null( $actual );
        if ( '' === $operator || null === $value || null === $actualValue ) {
            return true;
        }
        return match ( $operator ) {
            '>' => $actualValue > $value,
            '>=' => $actualValue >= $value,
            '<' => $actualValue < $value,
            '<=' => $actualValue <= $value,
            '=' => abs( $actualValue - $value ) < 0.00005,
            '!=' => abs( $actualValue - $value ) >= 0.00005,
            default => true,
        };
    }

    /**
     * @param array<string,mixed> $validity
     */
    private function is_valid_now( array $validity ) : bool {
        $now = current_time( 'mysql', true );
        $start = $this->normalize_datetime( $validity['start_at'] ?? null );
        $end = $this->normalize_datetime( $validity['end_at'] ?? null );
        if ( null !== $start && $now < $start ) {
            return false;
        }
        if ( null !== $end && $now > $end ) {
            return false;
        }
        return true;
    }

    /**
     * @param mixed $value
     */
    private function normalize_datetime( $value ) : ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }
        $stamp = strtotime( (string) $value );
        if ( false === $stamp ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $stamp );
    }

    /**
     * @param mixed $value
     */
    private function float_or_null( $value ) : ?float {
        if ( null === $value || '' === $value ) {
            return null;
        }
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        return (float) $value;
    }

    /**
     * @param mixed $value
     */
    private function float_or_default( $value, float $default ) : float {
        $parsed = $this->float_or_null( $value );
        if ( null === $parsed ) {
            return $default;
        }
        return $parsed;
    }

    /**
     * @param mixed $value
     * @return array<int>
     */
    private function sanitize_int_array( $value ) : array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $ids = array_values( array_filter( array_map( 'intval', $value ), static fn( int $id ) : bool => $id > 0 ) );
        return array_values( array_unique( $ids ) );
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function sanitize_text_array( $value ) : array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $items = [];
        foreach ( $value as $item ) {
            $clean = sanitize_text_field( (string) $item );
            if ( '' !== $clean ) {
                $items[] = $clean;
            }
        }
        return array_values( array_unique( $items ) );
    }

    private function brand_taxonomy() : ?string {
        if ( null !== $this->brandTaxonomy ) {
            return $this->brandTaxonomy;
        }
        if ( taxonomy_exists( 'product_brand' ) ) {
            $this->brandTaxonomy = 'product_brand';
            return $this->brandTaxonomy;
        }
        if ( taxonomy_exists( 'pa_brand' ) ) {
            $this->brandTaxonomy = 'pa_brand';
            return $this->brandTaxonomy;
        }
        $this->brandTaxonomy = '';
        return null;
    }

    /**
     * @param array<int|string> $terms
     * @return array<int>
     */
    private function resolve_brand_terms_to_ids( array $terms ) : array {
        $taxonomy = $this->brand_taxonomy();
        if ( null === $taxonomy || empty( $terms ) ) {
            return [];
        }
        $ids = [];
        foreach ( $terms as $term ) {
            if ( is_numeric( $term ) ) {
                $id = (int) $term;
                if ( $id > 0 ) {
                    $ids[] = $id;
                }
                continue;
            }
            $slug = sanitize_title( (string) $term );
            if ( '' === $slug ) {
                continue;
            }
            $found = get_term_by( 'slug', $slug, $taxonomy );
            if ( $found && ! is_wp_error( $found ) ) {
                $ids[] = (int) $found->term_id;
            }
        }
        return array_values( array_unique( array_filter( $ids, static fn( int $id ) : bool => $id > 0 ) ) );
    }

    /**
     * @param array<int>|null $base
     * @param array<int> $extra
     * @return array<int>
     */
    private function merge_intersection( ?array $base, array $extra ) : array {
        $extra = array_values( array_unique( array_filter( array_map( 'intval', $extra ), static fn( int $id ) : bool => $id > 0 ) ) );
        if ( null === $base ) {
            return $extra;
        }
        return array_values( array_intersect( $base, $extra ) );
    }

    /**
     * @param array<int> $termIds
     * @return array<int>
     */
    private function query_taxonomy_products( string $taxonomy, array $termIds ) : array {
        $termIds = array_values( array_filter( array_map( 'intval', $termIds ), static fn( int $v ) : bool => $v > 0 ) );
        if ( empty( $termIds ) ) {
            return [];
        }
        $idsIn = implode( ',', $termIds );
        $sql = $this->db->prepare(
            "SELECT DISTINCT p.ID
             FROM {$this->db->posts} p
             INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type='product'
               AND p.post_status='publish'
               AND tt.taxonomy = %s
               AND tt.term_id IN ({$idsIn})",
            $taxonomy
        );
        return array_values( array_map( 'intval', $this->db->get_col( $sql ) ?: [] ) );
    }

    /**
     * @param array<int,string> $values
     * @return array<int>
     */
    private function query_postmeta_values( string $metaKey, array $values ) : array {
        $values = array_values( array_filter( array_map( 'strval', $values ), static fn( string $v ) : bool => '' !== $v ) );
        if ( empty( $values ) ) {
            return [];
        }
        $metaKeySql = $this->db->prepare( '%s', $metaKey );
        $valuesSql = implode( ',', array_map( [ $this->db, 'prepare' ], array_fill( 0, count( $values ), '%s' ), $values ) );
        $sql = "SELECT DISTINCT p.ID
                FROM {$this->db->posts} p
                INNER JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID
                WHERE p.post_type='product'
                  AND p.post_status='publish'
                  AND pm.meta_key = {$metaKeySql}
                  AND pm.meta_value IN ({$valuesSql})";
        return array_values( array_map( 'intval', $this->db->get_col( $sql ) ?: [] ) );
    }

    /**
     * @return array<int>
     */
    private function query_stock_condition_products( string $condition ) : array {
        $lookup = $this->db->prefix . 'wc_product_meta_lookup';
        $hasLookup = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $lookup ) );
        if ( $hasLookup ) {
            $op = 'eq_0' === $condition ? '= 0' : '> 0';
            $sql = "SELECT DISTINCT p.ID
                    FROM {$this->db->posts} p
                    INNER JOIN {$lookup} wcpl ON wcpl.product_id = p.ID
                    WHERE p.post_type='product'
                      AND p.post_status='publish'
                      AND COALESCE(wcpl.stock_quantity, 0) {$op}";
            return array_values( array_map( 'intval', $this->db->get_col( $sql ) ?: [] ) );
        }
        $op = 'eq_0' === $condition ? '= 0' : '> 0';
        $sql = "SELECT DISTINCT p.ID
                FROM {$this->db->posts} p
                INNER JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID
                WHERE p.post_type='product'
                  AND p.post_status='publish'
                  AND pm.meta_key = '_stock'
                  AND CAST(COALESCE(pm.meta_value, '0') AS SIGNED) {$op}";
        return array_values( array_map( 'intval', $this->db->get_col( $sql ) ?: [] ) );
    }
}

