<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/Repricer/RepricePriceEngine.php';

$fixturesFile = $root . '/tools/tests/fixtures/repricer_price_logic_cases.json';
if ( ! file_exists( $fixturesFile ) ) {
    fwrite( STDERR, "Missing fixtures file: {$fixturesFile}\n" );
    exit( 2 );
}

$raw = file_get_contents( $fixturesFile );
$cases = json_decode( (string) $raw, true );
if ( ! is_array( $cases ) ) {
    fwrite( STDERR, "Invalid fixtures JSON: {$fixturesFile}\n" );
    exit( 2 );
}

$engine = new \Aurora\Enterprise\Repricer\RepricePriceEngine();
$failed = 0;
$total  = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
$matches = static function ( $expected, $actual ) : bool {
    if ( is_float( $expected ) || is_int( $expected ) ) {
        if ( ! is_numeric( $actual ) ) {
            return false;
        }
        return abs( (float) $expected - (float) $actual ) < 0.0002;
    }
    return $expected === $actual;
};

foreach ( $cases as $case ) {
    if ( ! is_array( $case ) ) {
        continue;
    }
    $total++;
    $name = (string) ( $case['name'] ?? "case_{$total}" );
    $input = is_array( $case['input'] ?? null ) ? $case['input'] : [];
    $config = is_array( $case['config'] ?? null ) ? $case['config'] : [];
    $expected = is_array( $case['expected'] ?? null ) ? $case['expected'] : [];

    $actual = $engine->evaluate( $input, $config );
    $caseFailed = false;
    foreach ( $expected as $key => $expectedValue ) {
        $actualValue = $actual[ $key ] ?? null;
        if ( ! $matches( $expectedValue, $actualValue ) ) {
            $caseFailed = true;
            fwrite(
                STDERR,
                sprintf(
                    "[FAIL] %s key=%s expected=%s actual=%s\n",
                    $name,
                    (string) $key,
                    json_encode( $expectedValue ),
                    json_encode( $actualValue )
                )
            );
        }
    }

    if ( $caseFailed ) {
        $failed++;
    } else {
        echo sprintf( "[PASS] %s\n", $name );
    }
}

echo sprintf( "TOTAL=%d FAILED=%d\n", $total, $failed );
exit( $failed > 0 ? 1 : 0 );
