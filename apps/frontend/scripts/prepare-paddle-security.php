<?php

use App\Models\BillingPrice;
use App\Support\Billing\Paddle\PaddleClient;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Contracts\Console\Kernel;

try {
    /** Run with Docker PHP 8.4 from apps/backend. API keys never leave the backend process. */
    require __DIR__.'/../../backend/vendor/autoload.php';
    $app = require __DIR__.'/../../backend/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! PaddleConfig::sandbox() || ! str_starts_with((string) PaddleConfig::clientToken(), 'test_')) {
        throw new RuntimeException('Sandbox configuration required');
    }

    if (count($argv) > 2 || array_diff(array_slice($argv, 1), ['--cancel', '--inspect']) !== []) {
        throw new RuntimeException('Expected no argument, --cancel or --inspect');
    }

    $client = app(PaddleClient::class);

    if (in_array('--cancel', $argv, true) || in_array('--inspect', $argv, true)) {
        $context = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $ids = array_unique(array_merge(array_values($context['transactions'] ?? []), $context['previous_transaction_ids'] ?? []));
        if (($context['environment'] ?? null) !== 'sandbox' || $ids === []) {
            throw new RuntimeException('Nonempty sandbox smoke context required');
        }
        $states = [];
        $attempts = [];
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^txn_[a-z0-9]+$/D', $id)) {
                throw new RuntimeException('Invalid sandbox transaction reference');
            }
            $data = $client->get('/transactions/'.$id)['data'];
            if (($data['custom_data']['purpose'] ?? null) !== 'security_headers_no_charge_smoke') {
                throw new RuntimeException('Transaction does not belong to this smoke');
            }
            if (in_array('--inspect', $argv, true)) {
                $states[] = ['status' => $data['status'], 'purpose_matches' => ($data['custom_data']['purpose'] ?? null) === 'security_headers_no_charge_smoke', 'subscription_linked' => ! empty($data['subscription_id']), 'payment_statuses' => array_column($data['payments'] ?? [], 'status')];

                continue;
            }
            $paymentStatuses = array_column($data['payments'] ?? [], 'status');
            if (count($paymentStatuses) !== count($data['payments'] ?? []) || array_diff($paymentStatuses, ['created', 'action_required', 'error', 'dropped', 'canceled']) !== []) {
                throw new RuntimeException('Unexpected authorized/captured/unknown payment attempt; cleanup stopped');
            }
            if (($data['custom_data']['purpose'] ?? null) !== 'security_headers_no_charge_smoke'
                || ! empty($data['subscription_id'])
                || ! in_array($data['status'], ['draft', 'ready', 'canceled'], true)) {
                throw new RuntimeException('Unexpected transaction state; no cleanup mutation performed for this reference');
            }
            $states[] = $data['status'];
            array_push($attempts, ...$paymentStatuses);
            if ($data['status'] !== 'canceled') {
                if ($client->patch('/transactions/'.$id, ['status' => 'canceled'])['data']['status'] !== 'canceled') {
                    throw new RuntimeException('Cancellation was not confirmed');
                }
            }
        }
        if (in_array('--inspect', $argv, true)) {
            echo json_encode($states, JSON_THROW_ON_ERROR);
            exit;
        }
        echo json_encode(['transactions_checked' => count($states), 'before_cleanup' => $states, 'payment_attempt_statuses' => $attempts, 'captured_attempts' => count(array_filter($attempts, fn ($status) => $status === 'captured')), 'authorized_attempts' => count(array_filter($attempts, fn ($status) => in_array($status, ['authorized', 'authorized_flagged'], true))), 'subscriptions' => 0, 'canceled' => count($states)], JSON_THROW_ON_ERROR);
        exit;
    }

    $price = BillingPrice::query()->where('mode', 'test')->where('price_kind', 'base')->whereNotNull('provider_price_ref')->firstOrFail();
    $transactions = [];
    foreach (['public-pay', 'customer-checkout'] as $scenario) {
        $data = $client->post('/transactions', ['items' => [['price_id' => $price->provider_price_ref, 'quantity' => 1]], 'collection_mode' => 'automatic', 'custom_data' => ['purpose' => 'security_headers_no_charge_smoke', 'scenario' => $scenario]])['data'];
        if (! in_array($data['status'], ['draft', 'ready'], true) || ! empty($data['payments']) || ! empty($data['subscription_id'])) {
            throw new RuntimeException('Unexpected sandbox transaction state');
        }
        $transactions[$scenario] = $data['id'];
    }
    echo json_encode(['environment' => 'sandbox', 'client_token' => PaddleConfig::clientToken(), 'transactions' => $transactions, 'created_status' => 'draft'], JSON_THROW_ON_ERROR);

} catch (Throwable $error) {
    fwrite(STDERR, 'Paddle sandbox fixture failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
