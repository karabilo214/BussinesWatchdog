<?php

use App\Models\CheckAttempt;
use App\Models\CheckoutObservationWindow;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\PaymentAttemptWindow;
use App\Support\Checkout\CheckoutObservationEvaluator;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Stores\StoreCoverage;

$action = getenv('BW_E2E_ACTION');
$out = [];
$store = \App\Models\Store::query()->find(getenv('BW_E2E_STORE_ID'));

if ($action === 'state') {
    for ($i = 0; $i < 6; $i++) {
        app(DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
    }

    app(CheckoutObservationEvaluator::class)->evaluateAll();
    $out = [
        'counts' => collect(CheckoutObservationWindow::COUNTERS)->mapWithKeys(fn ($c) => [$c => (int) CheckoutObservationWindow::query()->where('store_id', $store->id)->sum($c)])->all(),
        'attempts' => (int) PaymentAttemptWindow::query()->where('store_id', $store->id)->selectRaw('coalesce(sum(paid + on_hold + failed + pending_stuck + late_success), 0) as n')->value('n'),
        'incidents' => Incident::query()->where('store_id', $store->id)->where('family', CheckoutObservationEvaluator::FAMILY)->orderBy('created_at')->get()->map(fn ($i) => $i->title_code.':'.$i->state)->all(),
        'coverage' => app(StoreCoverage::class)->forStores(collect([$store->refresh()]))[$store->id]['coverage']['checkout_script']['state'],
    ];
}

if ($action === 'check-status') {
    $run = CheckRun::query()->findOrFail(getenv('BW_E2E_RUN_ID'));
    $attempt = CheckAttempt::query()->where('run_id', $run->id)->with('steps')->first();
    $out = [
        'run_status' => $run->status,
        'expect_script' => $run->config_snapshot['expect_checkout_script'] ?? null,
        'script' => $attempt?->steps->firstWhere('step_code', 'payment_form')?->assertions ?? [],
        'errors' => collect($attempt?->sanitized_error['relevant_errors'] ?? [])->map(fn ($e) => ($e['type'] ?? '?').':'.($e['message_code'] ?? '').':'.($e['path'] ?? ''))->all(),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
