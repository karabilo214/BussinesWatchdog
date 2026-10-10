<?php

$productId = (int) getenv('BW_PRODUCT_ID');
$checkoutPageId = (int) getenv('BW_CHECKOUT_PAGE_ID');
$host = getenv('BW_HOST');
$cookies = [];

$request = function (string $method, string $path, ?string $body = null) use ($host, &$cookies): array {
    $curl = curl_init('http://localhost' . $path);
    $headers = ['Host: ' . $host, 'X-Forwarded-Proto: https', 'Content-Type: application/x-www-form-urlencoded'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    }
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$cookies) {
            if (stripos($line, 'set-cookie:') === 0) {
                $pair = explode('=', explode(';', trim(substr($line, 11)), 2)[0], 2);
                if (count($pair) === 2) { $cookies[$pair[0]] = $pair[1]; }
            }
            return strlen($line);
        }]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
    $content = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => $content, 'json' => json_decode($content, true)];
};

$request('POST', '/?wc-ajax=add_to_cart', http_build_query(['product_id' => $productId, 'quantity' => 1]));
$page = $request('GET', '/?page_id=' . $checkoutPageId);
if (! preg_match('/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $page['body'], $m)) {
    echo 'BW_SPIKE=' . json_encode(['error' => 'no_nonce', 'status' => $page['status']]) . PHP_EOL;
    return;
}
$fields = [
    'payment_method' => 'stripe',
    'woocommerce-process-checkout-nonce' => $m[1],
    '_wp_http_referer' => '/?page_id=' . $checkoutPageId,
    'wc-stripe-payment-method' => 'pm_card_visa',
    'wc-stripe-is-deferred-intent' => '1',
    'billing_first_name' => 'Spike', 'billing_last_name' => 'Tester', 'billing_address_1' => 'Teststrasse 1', 'billing_city' => 'Berlin',
    'billing_postcode' => '10115', 'billing_country' => 'DE', 'billing_email' => 'spike@example.test', 'billing_phone' => '030000000',
];
$response = $request('POST', '/?wc-ajax=checkout', http_build_query($fields));
$json = $response['json'];
echo 'BW_SPIKE=' . json_encode(['http' => $response['status'], 'result' => $json['result'] ?? null, 'messages' => isset($json['messages']) ? trim(strip_tags($json['messages'])) : null, 'order_id' => $json['order_id'] ?? null]) . PHP_EOL;
