<?php

namespace App\Http\Dto\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Collection;

class NotificationDeliveryDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(NotificationDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'incident_id' => $delivery->incident_id,
            'channel_id' => $delivery->channel_id,
            'notification_kind' => $delivery->notification_kind,
            'incident_revision' => $delivery->incident_revision,
            'template_version' => $delivery->template_version,
            'status' => $delivery->status,
            'delivery_uncertain' => $delivery->status === NotificationDelivery::STATUS_UNCERTAIN,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => in_array($delivery->status, NotificationDelivery::DUE_STATUSES, true)
                ? $delivery->next_attempt_at?->toJSON()
                : null,
            'sent_at' => $delivery->sent_at?->toJSON(),
            'error_code' => $delivery->error_code,
            'content' => (object) ($delivery->sanitized_content ?? []),
            'created_at' => $delivery->created_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $deliveries): array
    {
        return $deliveries->map(fn (NotificationDelivery $delivery): array => $this->toArray($delivery))->values()->all();
    }
}
