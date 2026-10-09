<?php

$host = $argv[1];
$productId = (int) $argv[2];
$checkoutPageId = (int) $argv[3];

final class BwMatrixClient
{
    public $cookies = [];

    public $headers = [];

    private $host;

    public function __construct(string $host)
    {
        $this->host = $host;
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $curl = curl_init('http://localhost' . $path);
        $sent = array_merge(['Host: ' . $this->host, 'X-Forwarded-Proto: https'], $headers);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $sent[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $sent,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = $value;
                    if ($name === 'set-cookie') {
                        $pair = explode('=', explode(';', $value, 2)[0], 2);
                        if (count($pair) === 2) {
                            $this->cookies[$pair[0]] = $pair[1];
                        }
                    }
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $content = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $this->headers = $responseHeaders;

        return ['status' => $status, 'body' => $content, 'json' => json_decode($content, true)];
    }
}

function bw_billing(string $email): array
{
    return [
        'first_name' => 'Matrix',
        'last_name' => 'Tester',
        'address_1' => 'Teststrasse 1',
        'city' => 'Berlin',
        'postcode' => '10115',
        'country' => 'DE',
        'email' => $email,
        'phone' => '030000000',
    ];
}

function bw_classic(string $host, int $productId, int $checkoutPageId, array $submissions): array
{
    $client = new BwMatrixClient($host);
    $results = [];

    foreach ($submissions as $label => $submission) {
        $client->request('POST', '/?wc-ajax=add_to_cart', http_build_query(['product_id' => $productId, 'quantity' => 1]), ['Content-Type: application/x-www-form-urlencoded']);
        $page = $client->request('GET', '/?page_id=' . $checkoutPageId);

        if (! preg_match('/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $page['body'], $match)) {
            $results[$label] = 'no_nonce(' . $page['status'] . ')';
            continue;
        }

        $fields = ['payment_method' => $submission['method'], 'woocommerce-process-checkout-nonce' => $match[1], '_wp_http_referer' => '/?page_id=' . $checkoutPageId];

        foreach (bw_billing($submission['email']) as $key => $value) {
            $fields['billing_' . $key] = $value;
        }

        $response = $client->request('POST', '/?wc-ajax=checkout', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
        $results[$label] = is_array($response['json']) ? ($response['json']['result'] ?? 'no_result') : 'http_' . $response['status'];
    }

    return $results;
}

function bw_blocks(string $host, int $productId, array $submissions): array
{
    $client = new BwMatrixClient($host);
    $namespace = '/wc/store/v1';
    $cart = $client->request('GET', '/?rest_route=' . $namespace . '/cart');

    if ($cart['status'] === 404) {
        $namespace = '/wc/store';
        $cart = $client->request('GET', '/?rest_route=' . $namespace . '/cart');
    }

    $results = ['namespace' => $namespace];

    foreach ($submissions as $label => $submission) {
        $nonce = $client->headers['nonce'] ?? ($client->headers['x-wc-store-api-nonce'] ?? '');
        $auth = ['Content-Type: application/json', 'Nonce: ' . $nonce, 'X-WC-Store-API-Nonce: ' . $nonce];

        if (isset($client->headers['cart-token'])) {
            $auth[] = 'Cart-Token: ' . $client->headers['cart-token'];
        }

        $add = $client->request('POST', '/?rest_route=' . $namespace . '/cart/add-item', json_encode(['id' => $productId, 'quantity' => 1]), $auth);
        $nonce = $client->headers['nonce'] ?? ($client->headers['x-wc-store-api-nonce'] ?? $nonce);
        $auth[1] = 'Nonce: ' . $nonce;
        $auth[2] = 'X-WC-Store-API-Nonce: ' . $nonce;

        $response = $client->request('POST', '/?rest_route=' . $namespace . '/checkout', json_encode([
            'billing_address' => bw_billing($submission['email']),
            'shipping_address' => array_diff_key(bw_billing($submission['email']), ['email' => true]),
            'payment_method' => $submission['method'],
        ]), $auth);

        $code = is_array($response['json']) ? ($response['json']['code'] ?? ($response['json']['payment_result']['payment_status'] ?? $response['json']['status'] ?? 'ok')) : 'no_json';
        $results[$label] = $add['status'] . '/' . $response['status'] . ':' . $code;
        $client->request('GET', '/?rest_route=' . $namespace . '/cart');
    }

    return $results;
}

$submissions = [
    'decline' => ['method' => 'bw_test_decline', 'email' => 'matrix@example.test'],
    'invalid' => ['method' => 'bacs', 'email' => 'not-an-email'],
    'bacs' => ['method' => 'bacs', 'email' => 'matrix@example.test'],
];

echo 'BW_CHECKOUT=' . json_encode([
    'classic' => bw_classic($host, $productId, $checkoutPageId, $submissions),
    'blocks' => bw_blocks($host, $productId, $submissions),
]) . PHP_EOL;
