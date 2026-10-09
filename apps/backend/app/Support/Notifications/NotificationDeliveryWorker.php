<?php

namespace App\Support\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\Channels\SendResult;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryWorker
{
    public const RETRY_DELAYS_SECONDS = [60, 300, 900, 3600, 21600];

    public const DEAD_LETTER_AFTER_HOURS = 24;

    public const ERROR_CHANNEL_UNAVAILABLE = 'channel_unavailable';

    public const ERROR_CHANNEL_KIND_UNSUPPORTED = 'channel_kind_unsupported';

    public const ERROR_DESTINATION_UNREADABLE = 'destination_unreadable';

    public const ERROR_WORKER_LOST_DURING_SEND = 'worker_lost_during_send';

    public const ERROR_RETRY_WINDOW_EXHAUSTED = 'retry_window_exhausted';

    public function __construct(
        private readonly NotificationSenderRegistry $senders,
        private readonly NotificationRenderer $renderer,
    ) {}

    /**
     * @return array{claimed: int, sent: int, failed: int, uncertain: int, dead_letter: int, suppressed: int, swept: int}
     */
    public function deliverDue(int $limit = 50, int $sendingLeaseSeconds = 300): array
    {
        $totals = [
            'claimed' => 0,
            NotificationDelivery::STATUS_SENT => 0,
            NotificationDelivery::STATUS_FAILED => 0,
            NotificationDelivery::STATUS_UNCERTAIN => 0,
            NotificationDelivery::STATUS_DEAD_LETTER => 0,
            NotificationDelivery::STATUS_SUPPRESSED => 0,
            'swept' => $this->sweepLostSends(),
        ];

        $claimed = $this->claimDue(max(1, min($limit, 200)), max(30, min($sendingLeaseSeconds, 3600)));
        $totals['claimed'] = $claimed->count();

        foreach ($claimed as $delivery) {
            $status = $this->process($delivery);

            if ($status !== null) {
                $totals[$status]++;
            }
        }

        return $totals;
    }

    private function sweepLostSends(): int
    {
        return NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_SENDING)
            ->where('next_attempt_at', '<=', Carbon::now())
            ->update([
                'status' => NotificationDelivery::STATUS_UNCERTAIN,
                'error_code' => self::ERROR_WORKER_LOST_DURING_SEND,
            ]);
    }

    /**
     * @return Collection<int, NotificationDelivery>
     */
    private function claimDue(int $limit, int $leaseSeconds): Collection
    {
        return DB::transaction(function () use ($limit, $leaseSeconds): Collection {
            $now = Carbon::now();

            $deliveries = NotificationDelivery::query()
                ->whereIn('status', NotificationDelivery::DUE_STATUSES)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($deliveries as $delivery) {
                $delivery->forceFill([
                    'status' => NotificationDelivery::STATUS_SENDING,
                    'attempts' => $delivery->attempts + 1,
                    'next_attempt_at' => $now->copy()->addSeconds($leaseSeconds),
                ])->save();
            }

            return $deliveries;
        });
    }

    private function process(NotificationDelivery $delivery): ?string
    {
        /** @var NotificationChannel|null $channel */
        $channel = NotificationChannel::query()
            ->where('tenant_id', $delivery->tenant_id)
            ->whereKey($delivery->channel_id)
            ->first();

        if ($channel === null || ! $channel->isDeliverable()) {
            return $this->finish($delivery, NotificationDelivery::STATUS_SUPPRESSED, self::ERROR_CHANNEL_UNAVAILABLE);
        }

        $sender = $this->senders->for($channel->kind);

        if ($sender === null) {
            return $this->finish($delivery, NotificationDelivery::STATUS_DEAD_LETTER, self::ERROR_CHANNEL_KIND_UNSUPPORTED, $channel);
        }

        try {
            $destination = Crypt::decryptString($channel->destination_ciphertext);
        } catch (DecryptException) {
            return $this->finish($delivery, NotificationDelivery::STATUS_DEAD_LETTER, self::ERROR_DESTINATION_UNREADABLE, $channel);
        }

        $result = $sender->send($destination, $this->renderer->render($delivery->sanitized_content ?? []));

        return match ($result->outcome) {
            SendResult::OUTCOME_SENT => $this->finish($delivery, NotificationDelivery::STATUS_SENT, null, $channel, $result->providerMessageId),
            SendResult::OUTCOME_UNCERTAIN => $this->finish($delivery, NotificationDelivery::STATUS_UNCERTAIN, $result->errorCode),
            SendResult::OUTCOME_PERMANENT_FAILURE => $this->finish($delivery, NotificationDelivery::STATUS_DEAD_LETTER, $result->errorCode, $channel),
            default => $this->scheduleRetry($delivery, $channel, $result),
        };
    }

    private function scheduleRetry(NotificationDelivery $delivery, NotificationChannel $channel, SendResult $result): ?string
    {
        $index = min(max($delivery->attempts - 1, 0), count(self::RETRY_DELAYS_SECONDS) - 1);
        $delay = max(self::RETRY_DELAYS_SECONDS[$index], $result->retryAfterSeconds ?? 0);
        $nextAttemptAt = Carbon::now()->addSeconds($delay);
        $deadline = $delivery->created_at->copy()->addHours(self::DEAD_LETTER_AFTER_HOURS);

        if ($nextAttemptAt->greaterThanOrEqualTo($deadline)) {
            return $this->finish(
                $delivery,
                NotificationDelivery::STATUS_DEAD_LETTER,
                $result->errorCode ?? self::ERROR_RETRY_WINDOW_EXHAUSTED,
                $channel,
            );
        }

        return DB::transaction(function () use ($delivery, $result, $nextAttemptAt): ?string {
            $locked = $this->lockSending($delivery);

            if ($locked === null) {
                return null;
            }

            $locked->forceFill([
                'status' => NotificationDelivery::STATUS_FAILED,
                'next_attempt_at' => $nextAttemptAt,
                'error_code' => $result->errorCode,
            ])->save();

            return NotificationDelivery::STATUS_FAILED;
        });
    }

    private function finish(
        NotificationDelivery $delivery,
        string $status,
        ?string $errorCode,
        ?NotificationChannel $channel = null,
        ?string $providerMessageId = null,
    ): ?string {
        return DB::transaction(function () use ($delivery, $status, $errorCode, $channel, $providerMessageId): ?string {
            $locked = $this->lockSending($delivery);

            if ($locked === null) {
                return null;
            }

            $now = Carbon::now();
            $locked->forceFill([
                'status' => $status,
                'error_code' => $errorCode,
                'provider_message_id' => $providerMessageId,
                'sent_at' => $status === NotificationDelivery::STATUS_SENT ? $now : $locked->sent_at,
                'next_attempt_at' => $now,
            ])->save();

            if ($channel !== null && $status === NotificationDelivery::STATUS_SENT) {
                $this->updateHealth($channel, ['status' => 'ok', 'last_success_at' => $now->toJSON()]);
            } elseif ($channel !== null && $status === NotificationDelivery::STATUS_DEAD_LETTER) {
                $this->updateHealth($channel, [
                    'status' => 'failing',
                    'last_error_code' => $errorCode,
                    'last_dead_letter_at' => $now->toJSON(),
                ]);
            }

            return $status;
        });
    }

    private function lockSending(NotificationDelivery $delivery): ?NotificationDelivery
    {
        /** @var NotificationDelivery|null $locked */
        $locked = NotificationDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

        return $locked?->status === NotificationDelivery::STATUS_SENDING ? $locked : null;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateHealth(NotificationChannel $channel, array $changes): void
    {
        /** @var NotificationChannel $locked */
        $locked = NotificationChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
        $locked->forceFill([
            'health' => array_merge($locked->health ?? [], $changes),
            'updated_at' => Carbon::now(),
        ])->save();
    }
}
