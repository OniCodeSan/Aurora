<?php
require "/var/www/html/wp-load.php";

if ( ! defined( "REST_REQUEST" ) ) {
    define( "REST_REQUEST", true );
}

/**
 * @return array{user_id:int,nonce:string}
 */
function aurora_dashboard_smoke_bootstrap_auth() : array {
    $userId = 1;
    $user = get_user_by( "id", $userId );
    if ( ! $user ) {
        $admins = get_users(
            [
                "role__in" => [ "administrator" ],
                "number"   => 1,
                "fields"   => "ids",
            ]
        );
        if ( ! empty( $admins ) ) {
            $userId = (int) $admins[0];
        }
    }
    wp_set_current_user( $userId );
    $nonce = wp_create_nonce( "wp_rest" );
    $_SERVER["HTTP_X_WP_NONCE"] = $nonce;
    return [
        "user_id" => $userId,
        "nonce"   => $nonce,
    ];
}

/**
 * @param array<string,mixed> $params
 * @return array{code:int,data:mixed,error:string}
 */
function aurora_dashboard_smoke_dispatch( string $method, string $route, array $params = [] ) : array {
    $request = new WP_REST_Request( $method, $route );
    foreach ( $params as $key => $value ) {
        $request->set_param( (string) $key, $value );
    }
    $response = rest_do_request( $request );
    if ( is_wp_error( $response ) ) {
        $status = (int) ( $response->get_error_data()["status"] ?? 500 );
        return [
            "code"  => $status,
            "data"  => [],
            "error" => $response->get_error_message(),
        ];
    }

    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
    $code = $response instanceof WP_REST_Response ? (int) $response->get_status() : 200;
    $error = "";
    if ( is_array( $data ) && isset( $data["message"] ) && $code >= 400 ) {
        $error = (string) $data["message"];
    }

    return [
        "code"  => $code,
        "data"  => $data,
        "error" => $error,
    ];
}

/**
 * @param array<string,mixed> $summaryData
 */
function aurora_dashboard_smoke_validate_summary( array $summaryData ) : bool {
    foreach ( [ "status", "reasons", "kpis", "alerts" ] as $key ) {
        if ( ! array_key_exists( $key, $summaryData ) ) {
            return false;
        }
    }
    return true;
}

function aurora_dashboard_smoke_print_result( string $label, bool $ok, string $details = "" ) : void {
    $status = $ok ? "PASS" : "FAIL";
    if ( $details !== "" ) {
        echo "{$label}: {$status} ({$details})\n";
        return;
    }
    echo "{$label}: {$status}\n";
}

$auth = aurora_dashboard_smoke_bootstrap_auth();
$userId = (int) $auth["user_id"];
$rateKey = sprintf( "aurora_dashboard_rate_%d_%s", $userId, "tick_scheduler" );
delete_transient( $rateKey );

$allOk = true;

$summaryRes = aurora_dashboard_smoke_dispatch( "GET", "/aurora/v1/dashboard/summary" );
$summaryPayload = [];
if ( is_array( $summaryRes["data"] ) ) {
    $summaryPayload = isset( $summaryRes["data"]["summary"] ) && is_array( $summaryRes["data"]["summary"] )
        ? $summaryRes["data"]["summary"]
        : $summaryRes["data"];
}
$summaryOk = ( 200 === $summaryRes["code"] ) && aurora_dashboard_smoke_validate_summary( $summaryPayload );
aurora_dashboard_smoke_print_result( "GET /dashboard/summary", $summaryOk, "http=" . $summaryRes["code"] );
$allOk = $allOk && $summaryOk;

$runsRes = aurora_dashboard_smoke_dispatch( "GET", "/aurora/v1/dashboard/runs", [ "limit" => 20 ] );
$runsOk = ( 200 === $runsRes["code"] ) && is_array( $runsRes["data"] ) && isset( $runsRes["data"]["runs"] ) && is_array( $runsRes["data"]["runs"] );
aurora_dashboard_smoke_print_result( "GET /dashboard/runs", $runsOk, "http=" . $runsRes["code"] );
$allOk = $allOk && $runsOk;

$eventsRes = aurora_dashboard_smoke_dispatch( "GET", "/aurora/v1/dashboard/events", [ "limit" => 10 ] );
$eventsOk = ( 200 === $eventsRes["code"] ) && is_array( $eventsRes["data"] ) && isset( $eventsRes["data"]["events"] ) && is_array( $eventsRes["data"]["events"] );
aurora_dashboard_smoke_print_result( "GET /dashboard/events", $eventsOk, "http=" . $eventsRes["code"] );
$allOk = $allOk && $eventsOk;

$actionResOne = aurora_dashboard_smoke_dispatch( "POST", "/aurora/v1/dashboard/action", [ "action" => "tick_scheduler" ] );
$actionOneOk = ( 200 === $actionResOne["code"] ) && is_array( $actionResOne["data"] ) && array_key_exists( "success", $actionResOne["data"] ) && array_key_exists( "message", $actionResOne["data"] ) && array_key_exists( "data", $actionResOne["data"] );
aurora_dashboard_smoke_print_result( "POST /dashboard/action first", $actionOneOk, "http=" . $actionResOne["code"] );
$allOk = $allOk && $actionOneOk;

$actionResTwo = aurora_dashboard_smoke_dispatch( "POST", "/aurora/v1/dashboard/action", [ "action" => "tick_scheduler" ] );
$actionTwoOk = ( 429 === $actionResTwo["code"] );
aurora_dashboard_smoke_print_result( "POST /dashboard/action second (rate limit)", $actionTwoOk, "http=" . $actionResTwo["code"] );
$allOk = $allOk && $actionTwoOk;

if ( $allOk ) {
    echo "RESULT=PASS\n";
    exit( 0 );
}

echo "RESULT=FAIL\n";
exit( 1 );
