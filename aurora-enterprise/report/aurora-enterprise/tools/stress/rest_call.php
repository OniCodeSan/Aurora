<?php
require "/var/www/html/wp-load.php";

$route = $argv[1] ?? '';
$bodyJson = $argv[2] ?? '{}';

if ($route === '') {
    fwrite(STDERR, "missing route\n");
    exit(1);
}

$body = json_decode($bodyJson, true);
if (!is_array($body)) {
    $body = [];
}

wp_set_current_user(1);
if (!defined('REST_REQUEST')) {
    define('REST_REQUEST', true);
}
$_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");

$request = new WP_REST_Request("POST", $route);
foreach ($body as $key => $value) {
    $request->set_param($key, $value);
}

$response = rest_do_request($request);

if ($response instanceof WP_Error) {
    $payload = [
        "ok" => false,
        "route" => $route,
        "error" => $response->get_error_message(),
        "data" => $response->get_error_data(),
    ];
    echo wp_json_encode($payload) . "\n";
    exit(0);
}

$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

$payload = [
    "ok" => true,
    "route" => $route,
    "response" => $data,
];

echo wp_json_encode($payload) . "\n";
