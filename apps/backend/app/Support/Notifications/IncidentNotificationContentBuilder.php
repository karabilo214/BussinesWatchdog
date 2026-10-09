<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Models\Store;
use App\Support\Checkout\PaymentAttemptMonitor;

class IncidentNotificationContentBuilder
{
    public const TEMPLATE_VERSION = 'incident.v1';

    public const TEST_TEMPLATE_VERSION = 'test.v1';

    /**
     * @return array<string, mixed>
     */
    public function forIncident(Incident $incident, Store $store, string $kind, NotificationPreferences $preferences): array
    {
        if ($incident->family === PaymentAttemptMonitor::FAMILY) {
            return $this->forPaymentAttempts($incident, $store, $kind, $preferences);
        }

        $finding = $this->latestMismatchFinding($incident);
        $orderNumber = null;

        if ($finding?->order_id !== null) {
            $orderNumber = Order::query()
                ->where('tenant_id', $incident->tenant_id)
                ->where('store_id', $incident->store_id)
                ->whereKey($finding->order_id)
                ->value('display_number');
        }

        $exponent = $finding?->currency_exponent;
        $amountKnown = $incident->verified_discrepancy_minor !== null
            && $incident->currency !== null
            && $exponent !== null;

        return [
            'kind' => $kind,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'store_name' => $store->name,
            'severity' => $incident->severity,
            'family' => $incident->family,
            'component' => $incident->component,
            'rule_code' => $incident->title_code,
            'order_number' => $orderNumber,
            'currency' => $amountKnown ? $incident->currency : null,
            'amount_minor' => $amountKnown ? (string) $incident->verified_discrepancy_minor : null,
            'currency_exponent' => $amountKnown ? $exponent : null,
            'possible_start_from' => $incident->last_good_at?->toJSON(),
            'possible_start_to' => ($incident->first_bad_at ?? $incident->first_seen_at)?->toJSON(),
            'recovered_at' => $kind === NotificationDelivery::KIND_INCIDENT_RECOVERED
                ? $incident->resolved_at?->toJSON()
                : null,
            'source' => 'reconciliation',
            'checked_steps' => ['store_order_data', 'provider_transactions', 'payment_allocations'],
            'link' => $this->incidentLink($incident),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forTest(string $label, NotificationPreferences $preferences): array
    {
        return [
            'kind' => NotificationDelivery::KIND_TEST,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'channel_label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forPaymentAttempts(Incident $incident, Store $store, string $kind, NotificationPreferences $preferences): array
    {
        $evidence = Signal::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('store_id', $incident->store_id)
            ->where('signal_type', Signal::TYPE_PAYMENT_ATTEMPTS)
            ->whereIn('id', IncidentSignal::query()
                ->where('incident_id', $incident->id)
                ->select('signal_id'))
            ->where('severity', '!=', Signal::SEVERITY_INFO)
            ->orderByDesc('detected_at')
            ->value('evidence') ?? [];

        if (is_string($evidence)) {
            $evidence = json_decode($evidence, true) ?: [];
        }

        return [
            'kind' => $kind,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'store_name' => $store->name,
            'severity' => $incident->severity,
            'family' => $incident->family,
            'component' => $incident->component,
            'rule_code' => $incident->title_code,
            'order_number' => null,
            'payment_method' => is_string($evidence['payment_method'] ?? null) ? $evidence['payment_method'] : null,
            'failure_streak' => is_int($evidence['failure_streak'] ?? null) ? $evidence['failure_streak'] : null,
            'currency' => null,
            'amount_minor' => null,
            'currency_exponent' => null,
            'possible_start_from' => $incident->last_good_at?->toJSON(),
            'possible_start_to' => ($incident->first_bad_at ?? $incident->first_seen_at)?->toJSON(),
            'recovered_at' => $kind === NotificationDelivery::KIND_INCIDENT_RECOVERED
                ? $incident->resolved_at?->toJSON()
                : null,
            'source' => 'payment_attempts',
            'checked_steps' => ['store_checkout_attempts'],
            'link' => $this->incidentLink($incident),
        ];
    }

    private function latestMismatchFinding(Incident $incident): ?ReconciliationFinding
    {
        $findingIds = Signal::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('store_id', $incident->store_id)
            ->whereIn('id', IncidentSignal::query()
                ->where('incident_id', $incident->id)
                ->select('signal_id'))
            ->whereNotNull('finding_id')
            ->pluck('finding_id');

        return ReconciliationFinding::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('store_id', $incident->store_id)
            ->whereIn('id', $findingIds)
            ->where('status', ReconciliationFinding::STATUS_MISMATCH)
            ->orderByDesc('evaluated_at')
            ->first();
    }

    private function incidentLink(Incident $incident): string
    {
        return rtrim((string) config('app.url'), '/').'/app/incidents/'.$incident->id;
    }
}
