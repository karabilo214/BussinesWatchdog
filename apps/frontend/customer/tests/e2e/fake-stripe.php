<?php

// Stand-in for api.stripe.com in the browser smoke test: GET-only list endpoints with Stripe-shaped objects.
header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (! str_starts_with($auth, 'Bearer rk_test_')) {
    http_response_code(401);
    echo json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']]);

    return;
}

$now = time();
$objects = [
    '/v1/payment_intents' => [
        ['id' => 'pi_check_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => 'ch_check_1', 'amount' => 4200, 'currency' => 'eur', 'livemode' => false, 'created' => $now - 1800],
    ],
    '/v1/charges' => [
        ['id' => 'ch_check_1', 'object' => 'charge', 'payment_intent' => 'pi_check_1', 'status' => 'succeeded', 'captured' => true, 'amount_captured' => 4200, 'currency' => 'eur', 'livemode' => false, 'created' => $now - 1790],
    ],
    '/v1/refunds' => [],
];

if ($path === '/v1/account') {
    echo json_encode(['id' => 'acct_smokeCheck', 'object' => 'account']);

    return;
}

if (! isset($objects[$path])) {
    http_response_code(404);
    echo json_encode(['error' => ['type' => 'invalid_request_error']]);

    return;
}

$from = (int) ($_GET['created']['gte'] ?? 0);
$before = isset($_GET['created']['lt']) ? (int) $_GET['created']['lt'] : PHP_INT_MAX;
$data = array_values(array_filter($objects[$path], fn (array $object): bool => $object['created'] >= $from && $object['created'] < $before));

echo json_encode(['object' => 'list', 'data' => $data, 'has_more' => false]);
