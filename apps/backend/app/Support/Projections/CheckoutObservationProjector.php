<?php

namespace App\Support\Projections;

use App\Models\CheckoutObservationWindow;
use App\Models\EventInbox;
use App\Support\Ingest\EventProjectionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checkout observation counters of one window from the WooCommerce plugin (ADR 0059) into the same windows as the
 * Shopware app's script (ADR 0057). The plugin's checkout page is the confirm page there; a window is replaced only by
 * a newer revision, so duplicates and late or reordered deliveries change nothing.
 */
class CheckoutObservationProjector
{
    public const ERROR_INVALID = 'checkout_observation_invalid';

    private const MAPPING = [
        'checkout_rendered' => 'confirm_rendered',
        'checkout_rendered_noscript' => 'confirm_rendered_noscript',
        'finish_rendered' => 'finish_rendered',
        'script_loaded' => 'script_loaded',
        'form_ready' => 'form_ready',
        'returned_with_error' => 'returned_with_error',
        'submit_clicked' => 'submit_clicked',
        'submit_stalled' => 'submit_stalled',
        'finish_reached' => 'finish_reached',
        'js_errors' => 'js_errors',
    ];

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_CHECKOUT_OBSERVATION || $event->aggregate_type !== EventInbox::AGGREGATE_CHECKOUT) {
            return EventProjectionResult::ok();
        }

        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : null;
        $revision = $event->aggregate_revision;

        if ($data === null || $revision === null || ! is_string($data['window_start'] ?? null)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        $start = Carbon::parse($data['window_start'])->utc();
        $now = Carbon::now();
        $counters = [];

        foreach (self::MAPPING as $from => $to) {
            $counters[$to] = (int) ($data[$from] ?? 0);
        }

        DB::transaction(function () use ($event, $start, $revision, $counters, $now): void {
            /** @var CheckoutObservationWindow|null $window */
            $window = CheckoutObservationWindow::query()->where('store_id', $event->store_id)->where('window_start', $start)->lockForUpdate()->first();

            if ($window !== null && $window->source_revision !== null && $window->source_revision >= $revision) {
                return;
            }

            $attributes = [...$counters, 'integration_id' => $event->integration_id, 'event_id' => $event->id, 'source_revision' => $revision, 'updated_at' => $now];

            if ($window === null) {
                CheckoutObservationWindow::query()->create([...$attributes, 'tenant_id' => $event->tenant_id, 'store_id' => $event->store_id, 'window_start' => $start, 'created_at' => $now]);
            } else {
                $window->forceFill($attributes)->save();
            }
        });

        return EventProjectionResult::ok();
    }
}
