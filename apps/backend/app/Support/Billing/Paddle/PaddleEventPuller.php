<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingEventInbox;
use App\Models\BillingSyncCursor;
use Illuminate\Support\Carbon;

/**
 * Recovery path next to the webhook: reads Paddle's event log in id order after the stored cursor and records every
 * event in the same inbox (duplicates of webhook deliveries are recognised there). The cursor stops short of the last
 * two minutes, so an event that becomes visible a little late is not skipped.
 */
class PaddleEventPuller
{
    private const SETTLE_SECONDS = 120;

    public function __construct(
        private readonly PaddleClient $client,
        private readonly PaddleEventRecorder $recorder,
    ) {}

    /**
     * @return array{read: int, stored: int, complete: bool}
     */
    public function pull(): array
    {
        $cursor = BillingSyncCursor::query()->firstOrCreate([
            'provider' => PaddleConfig::PROVIDER,
            'provider_account_ref' => PaddleConfig::accountRef(),
            'mode' => PaddleConfig::mode(),
        ]);
        $settled = Carbon::now()->subSeconds(self::SETTLE_SECONDS);
        $summary = ['read' => 0, 'stored' => 0, 'complete' => false];

        try {
            for ($page = 0; $page < (int) config('watchdog.billing.paddle.events_max_pages_per_run'); $page++) {
                $result = $this->client->get('/events', array_filter([
                    'order_by' => 'id[ASC]',
                    'per_page' => (int) config('watchdog.billing.paddle.events_page_size'),
                    'after' => $cursor->cursor,
                ], fn (mixed $value): bool => $value !== null));
                $events = $result['data'];

                foreach ($events as $event) {
                    if (! is_array($event) || ! is_string($event['event_id'] ?? null)) {
                        continue;
                    }

                    $summary['read']++;
                    $outcome = $this->recorder->record($event, BillingEventInbox::SOURCE_EVENTS_API);
                    $summary['stored'] += $outcome === 'stored' ? 1 : 0;

                    if (Carbon::parse((string) ($event['occurred_at'] ?? 'now'))->greaterThan($settled)) {
                        $summary['complete'] = true;

                        break 2;
                    }

                    $cursor->cursor = $event['event_id'];
                }

                $cursor->save();

                if (($result['meta']['pagination']['has_more'] ?? false) !== true || $events === []) {
                    $summary['complete'] = true;

                    break;
                }
            }

            $cursor->forceFill(['last_success_at' => Carbon::now(), 'last_error_code' => null])->save();
        } catch (PaddleRequestFailed $exception) {
            $cursor->forceFill(['last_error_code' => $exception->reasonCode])->save();

            throw $exception;
        }

        return $summary;
    }
}
