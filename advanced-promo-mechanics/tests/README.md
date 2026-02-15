# Advanced Promo Mechanics – Test Notes

Use `wp-env` or WooCommerce local to bootstrap. Suggested commands:

```bash
wp scaffold plugin-tests advanced-promo-mechanics
wp phpunit --testsuite=woocommerce
```

Sample test idea (pseudo):

```php
public function test_quantity_discount_applies() {
    $rule = [ /* build array */ ];
    $engine = new \APM\Rules\Rule_Quantity_Discount( $rule, new DummyLogger() );
    $cart = WC()->cart;
    $result = $engine->apply( $cart );
    $this->assertTrue( $result['applied'] );
}
```
