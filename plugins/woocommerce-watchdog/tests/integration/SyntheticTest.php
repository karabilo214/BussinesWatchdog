<?php

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticOrders;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticRequest;

function bw_synthetic_token(array $overrides = [], ?string $secret = null): string
{
    $connection = Connection::current();
    $key = hash_hmac('sha256', 'bw-synthetic-v1', $secret ?? $connection->secretBytes(), true);
    $payload = rtrim(strtr(base64_encode((string) wp_json_encode(array_merge([
        'i' => $connection->integrationId(),
        'r' => '0199d0a4-1111-7222-8333-444455556666',
        'e' => time() + 300,
        'k' => $connection->keyId(),
    ], $overrides))), '+/', '-_'), '=');

    return 'v1.' . $payload . '.' . hash_hmac('sha256', 'v1.' . $payload, $key);
}

function bw_with_synthetic(?string $token, callable $callback)
{
    if ($token === null) {
        unset($_SERVER[SyntheticRequest::HEADER]);
    } else {
        $_SERVER[SyntheticRequest::HEADER] = $token;
    }

    SyntheticRequest::reset();

    try {
        return $callback();
    } finally {
        unset($_SERVER[SyntheticRequest::HEADER]);
        SyntheticRequest::reset();
    }
}

function bw_draft_status(): string
{
    return array_key_exists('wc-checkout-draft', wc_get_order_statuses()) ? 'checkout-draft' : 'pending';
}

bw_test('synthetic markers must be signed for this connection and unexpired', function () {
    bw_assert(SyntheticRequest::verify(bw_synthetic_token()) !== null, 'valid marker rejected');
    bw_assert(SyntheticRequest::verify(bw_synthetic_token() . 'a') === null, 'tampered signature accepted');
    bw_assert(SyntheticRequest::verify(bw_synthetic_token(['e' => time() - 1])) === null, 'expired marker accepted');
    bw_assert(SyntheticRequest::verify(bw_synthetic_token(['i' => wp_generate_uuid4()])) === null, 'marker for another connection accepted');
    bw_assert(SyntheticRequest::verify(bw_synthetic_token([], random_bytes(32))) === null, 'marker signed with another secret accepted');
    bw_assert(SyntheticRequest::verify('synthetic') === null && SyntheticRequest::verify('') === null, 'garbage accepted');
});

bw_test('orders created during a verified check are marked and flagged synthetic', function () {
    $order = bw_with_synthetic(bw_synthetic_token(), function () {
        $order = wc_create_order();
        $order->set_status(bw_draft_status());
        $order->save();

        return wc_get_order($order->get_id());
    });
    $plain = bw_with_synthetic('v1.forged.token', function () {
        return wc_create_order();
    });

    bw_assert($order->get_meta(SyntheticRequest::META_KEY) === '0199d0a4-1111-7222-8333-444455556666', 'synthetic order not marked');
    bw_assert(wc_get_order($plain->get_id())->get_meta(SyntheticRequest::META_KEY) === '', 'forged header marked an order');
});

bw_test('cleanup deletes only old synthetic checkout drafts', function () {
    if (bw_draft_status() !== 'checkout-draft') {
        $GLOBALS['bwObservations']['synthetic_cleanup'] = 'checkout-draft status not registered in this WooCommerce version';

        return;
    }

    $make = static function (bool $synthetic, string $status, int $age) {
        return bw_with_synthetic($synthetic ? bw_synthetic_token() : null, function () use ($status, $age) {
            $order = wc_create_order();
            $order->set_status($status);
            $order->set_date_created(time() - $age);
            $order->save();

            return $order->get_id();
        });
    };

    $oldSynthetic = $make(true, 'checkout-draft', 3600);
    $freshSynthetic = $make(true, 'checkout-draft', 0);
    $customerDraft = $make(false, 'checkout-draft', 3600);
    $syntheticPending = $make(true, 'pending', 3600);

    $deleted = SyntheticOrders::cleanup();

    bw_assert($deleted >= 1 && ! wc_get_order($oldSynthetic), 'old synthetic draft not deleted');
    bw_assert(wc_get_order($freshSynthetic) instanceof WC_Order, 'fresh synthetic draft deleted');
    bw_assert(wc_get_order($customerDraft) instanceof WC_Order, 'customer draft deleted');
    bw_assert(wc_get_order($syntheticPending) instanceof WC_Order, 'non-draft order deleted');
});
