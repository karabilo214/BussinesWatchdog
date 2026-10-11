<?php

$seed = json_decode((string) getenv('BW_SPIKE'), true);
$host = (string) getenv('BW_HOST');

function bw_call(string $method, string $path, ?array $body = null, bool $auth = true, bool $writeKey = false): array
{
    global $seed, $host;

    if (getenv('BW_REST_MODE') === 'pretty' && preg_match('#^/\?rest_route=([^&]+)(?:&(.*))?$#', $path, $match) === 1) {
        $path = '/wp-json' . $match[1] . (isset($match[2]) && $match[2] !== '' ? '?' . $match[2] : '');
    }

    $curl = curl_init('http://localhost' . $path);
    $headers = ['Host: ' . $host, 'X-Forwarded-Proto: https', 'Accept: application/json'];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $responseHeaders = [];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);

    if ($auth) {
        curl_setopt($curl, CURLOPT_USERPWD, $writeKey ? $seed['write_key'] . ':' . $seed['write_secret'] : $seed['key'] . ':' . $seed['secret']);
    }

    $start = microtime(true);
    $content = (string) curl_exec($curl);
    $ms = (int) round((microtime(true) - $start) * 1000);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'json' => json_decode($content, true), 'headers' => $responseHeaders, 'bytes' => strlen($content), 'ms' => $ms];
}

function bw_out(string $label, $value): void
{
    echo $label . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$code = static function (array $r): string {
    return $r['status'] . ':' . ($r['json']['code'] ?? '');
};

bw_out('index wc/v3', bw_call('GET', '/?rest_route=/wc/v3')['status']);
bw_out('no auth orders', $code(bw_call('GET', '/?rest_route=/wc/v3/orders', null, false)));

$order = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['stripe'])['json'];
bw_out('order keys', array_keys($order));
bw_out('order money fields', array_intersect_key($order, array_flip(['status', 'currency', 'total', 'prices_include_tax', 'payment_method', 'transaction_id', 'date_paid_gmt', 'date_modified_gmt', 'date_created_gmt', 'created_via', 'order_key'])));
bw_out('order meta keys visible', array_column($order['meta_data'] ?? [], 'key'));
bw_out('order refunds field', $order['refunds'] ?? null);
bw_out('billing present', isset($order['billing']['email']));

$refunds = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['stripe'] . '/refunds');
bw_out('refund keys', array_keys($refunds['json'][0] ?? []));
bw_out('refund fields', array_intersect_key($refunds['json'][0] ?? [], array_flip(['id', 'amount', 'date_created_gmt', 'refunded_payment', 'reason'])));
bw_out('refund meta keys visible', array_column($refunds['json'][0]['meta_data'] ?? [], 'key'));

$paypal = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['paypal'])['json'];
bw_out('paypal order meta keys visible', array_column($paypal['meta_data'] ?? [], 'key'));
$yen = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['yen'])['json'];
bw_out('yen total', [$yen['currency'] ?? null, $yen['total'] ?? null]);
$odd = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['odd'])['json'];
bw_out('odd total default dp', $odd['total'] ?? null);
$oddDp = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['odd'] . '&dp=6')['json'];
bw_out('odd total dp=6', $oddDp['total'] ?? null);

$fields = 'id,status,currency,total,payment_method,transaction_id,date_paid_gmt,date_modified_gmt,date_created_gmt,refunds,meta_data';
$slim = bw_call('GET', '/?rest_route=/wc/v3/orders&per_page=100&orderby=modified&order=asc&dates_are_gmt=true&modified_after=2000-01-01T00:00:00&_fields=' . $fields);
$full = bw_call('GET', '/?rest_route=/wc/v3/orders&per_page=100&orderby=modified&order=asc&dates_are_gmt=true&modified_after=2000-01-01T00:00:00');
bw_out('list status', [$slim['status'], $slim['json']['code'] ?? null]);
bw_out('list headers', array_intersect_key($slim['headers'], array_flip(['x-wp-total', 'x-wp-totalpages', 'link'])));
bw_out('list slim keys', array_keys($slim['json'][0] ?? []));
bw_out('list slim has billing', isset($slim['json'][0]['billing']));
bw_out('list page bytes full/slim, ms full/slim', [$full['bytes'], $slim['bytes'], $full['ms'], $slim['ms']]);
$ids = array_column(is_array($slim['json']) ? $slim['json'] : [], 'id');
bw_out('trashed listed by default', in_array($seed['trashed'], $ids, true));
$trash = bw_call('GET', '/?rest_route=/wc/v3/orders&status=trash&_fields=id,status');
bw_out('status=trash', [$trash['status'], array_column(is_array($trash['json']) ? $trash['json'] : [], 'status', 'id')]);
$any = bw_call('GET', '/?rest_route=/wc/v3/orders&status=any&per_page=100&_fields=id,status');
bw_out('status=any includes trash', in_array($seed['trashed'], array_column(is_array($any['json']) ? $any['json'] : [], 'id'), true));
bw_out('deleted order', $code(bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['deleted'])));
$include = bw_call('GET', '/?rest_route=/wc/v3/orders&status=any&include=' . $seed['stripe'] . ',' . $seed['deleted'] . ',' . $seed['trashed'] . '&_fields=id,status');
bw_out('include ids (exists check)', array_column(is_array($include['json']) ? $include['json'] : [], 'status', 'id'));

$before = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['stripe'] . '&_fields=status,date_modified_gmt')['json'];
bw_out('write PUT order', $code(bw_call('PUT', '/?rest_route=/wc/v3/orders/' . $seed['stripe'], ['status' => 'completed'])));
bw_out('write POST refund', $code(bw_call('POST', '/?rest_route=/wc/v3/orders/' . $seed['stripe'] . '/refunds', ['amount' => '1.00', 'api_refund' => false])));
bw_out('write DELETE order', $code(bw_call('DELETE', '/?rest_route=/wc/v3/orders/' . $seed['stripe'])));
bw_out('write POST order note', $code(bw_call('POST', '/?rest_route=/wc/v3/orders/' . $seed['stripe'] . '/notes', ['note' => 'x'])));
bw_out('write POST webhook', $code(bw_call('POST', '/?rest_route=/wc/v3/webhooks', ['topic' => 'order.updated', 'delivery_url' => 'https://example.com/hook'])));
bw_out('write PUT settings', $code(bw_call('PUT', '/?rest_route=/wc/v3/settings/general/woocommerce_currency', ['value' => 'USD'])));
bw_out('write POST product', $code(bw_call('POST', '/?rest_route=/wc/v3/products', ['name' => 'x'])));
$after = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['stripe'] . '&_fields=status,date_modified_gmt')['json'];
bw_out('order unchanged after writes', $before === $after);
bw_out('read webhooks list', $code(bw_call('GET', '/?rest_route=/wc/v3/webhooks')));
bw_out('read customers (PII)', $code(bw_call('GET', '/?rest_route=/wc/v3/customers')));
bw_out('read system_status', $code(bw_call('GET', '/?rest_route=/wc/v3/system_status')));
bw_out('read payment_gateways', $code(bw_call('GET', '/?rest_route=/wc/v3/payment_gateways')));
bw_out('read settings general', $code(bw_call('GET', '/?rest_route=/wc/v3/settings/general')));
$http = curl_init('http://localhost' . (getenv('BW_REST_MODE') === 'pretty' ? '/wp-json/wc/v3/orders' : '/?rest_route=/wc/v3/orders'));
curl_setopt_array($http, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . $host], CURLOPT_USERPWD => $seed['key'] . ':' . $seed['secret']]);
$plain = json_decode((string) curl_exec($http), true);
bw_out('basic auth over plain http', (int) curl_getinfo($http, CURLINFO_RESPONSE_CODE) . ':' . ($plain['code'] ?? ''));

$lastModified = bw_call('GET', '/?rest_route=/wc/v3/orders/' . $seed['paypal'] . '&_fields=date_modified_gmt')['json']['date_modified_gmt'] ?? null;
bw_out('paypal order modified before late refund', $lastModified);

bw_out('permission probe POST system_status, read key', $code(bw_call('POST', '/?rest_route=/wc/v3/system_status', [])));
bw_out('permission probe POST system_status, read_write key', $code(bw_call('POST', '/?rest_route=/wc/v3/system_status', [], true, true)));
bw_out('read_write key GET orders', $code(bw_call('GET', '/?rest_route=/wc/v3/orders&_fields=id', null, true, true)));
