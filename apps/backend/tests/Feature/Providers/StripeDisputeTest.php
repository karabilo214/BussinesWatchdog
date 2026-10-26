<?php

namespace Tests\Feature\Providers;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\ProviderDispute;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use App\Support\Disputes\DisputeIncidents;
use App\Support\Ingest\EventIngestor;
use App\Support\Notifications\IncidentNotificationContentBuilder;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\NotificationRenderer;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeDisputeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rk_test_51Smoke0123456789abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    /** @var array<string, list<array<string, mixed>>> */
    private array $stripe = ['payment_intents' => [], 'charges' => [], 'refunds' => [], 'disputes' => []];

    /** @var array<string, int> */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.stripe.api_base' => 'https://stripe.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin', 'locale' => 'de']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
        $this->woo = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [], 'connector_version' => '1.0.0', 'health' => ['freshness' => ['state' => 'fresh']], 'last_heartbeat_at' => now(),
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $resource = basename($path);

            if (isset($this->failures[$resource])) {
                return Http::response(['error' => ['type' => 'invalid_request_error']], $this->failures[$resource]);
            }

            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_1Smoke', 'object' => 'account']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $objects = array_values(array_filter($this->stripe[$resource] ?? [], fn (array $object): bool => $object['created'] >= (int) ($query['created']['gte'] ?? 0)
                && (! isset($query['created']['lte']) || $object['created'] <= (int) $query['created']['lte'])));
            usort($objects, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            return Http::response(['object' => 'list', 'data' => $objects, 'has_more' => false]);
        });
    }

    public function test_a_dispute_opens_a_warning_incident_linked_to_the_order_without_touching_the_reconciliation(): void
    {
        $order = $this->paidOrder();
        $due = now()->addDays(10)->getTimestamp();
        $this->stripe['disputes'][] = $this->dispute('du_1', 'needs_response', 'product_not_received', $due);
        $integration = $this->connected();

        $this->assertSame('ok', app(StripeSync::class)->run($integration)['status']);
        $this->drain();

        $dispute = ProviderDispute::query()->where('external_id', 'du_1')->firstOrFail();
        $this->assertSame(['needs_response', '8900', 'EUR', 'product_not_received'], [$dispute->status, $dispute->amount_minor, $dispute->currency, $dispute->reason]);
        $this->assertNotNull($dispute->payment_id);
        $this->assertSame($due, $dispute->evidence_due_by?->getTimestamp());
        $this->assertSame('ok', $integration->refresh()->health['sync']['disputes']['state']);

        $incident = Incident::query()->where('family', DisputeIncidents::FAMILY)->firstOrFail();
        $this->assertSame([Incident::SEVERITY_WARNING, DisputeIncidents::RULE_OPEN, Incident::STATE_OPEN], [$incident->severity, $incident->title_code, $incident->state]);
        $this->assertTrue(DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->where('payload->incident_id', $incident->id)->where('payload->notification_kind', NotificationDelivery::KIND_INCIDENT_OPENED)->exists());
        $this->assertFalse(FinancialTransaction::query()->where('kind', 'like', 'dispute%')->exists());
        $this->assertStringNotContainsString('buyer@example.test', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));
        $this->assertFalse(ReconciliationFinding::query()->where('order_id', $order->id)->where('status', 'mismatch')->exists());

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('order_display_number', '1042')
            ->assertJsonPath('dispute.status', 'needs_response')
            ->assertJsonPath('dispute.amount_minor', '8900')
            ->assertJsonPath('dispute.open', true);
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('disputes.0.external_id', 'du_1')
            ->assertJsonPath('disputes.0.reason', 'product_not_received');

        $content = app(IncidentNotificationContentBuilder::class)->forIncident($incident, $this->store, NotificationDelivery::KIND_INCIDENT_OPENED, NotificationPreferences::fromArray([], 'de', 'Europe/Berlin'));
        $letter = app(NotificationRenderer::class)->render($content);
        $this->assertSame('[Shop] Rückbuchung zu einer Bestellung', $letter->subject);
        $this->assertStringContainsString('Bestellung 1042 eine Rückbuchung (Chargeback) eröffnet. Grund: Ware nicht erhalten. Antworten Sie beim Zahlungsanbieter bis '.Carbon::createFromTimestampUTC($due)->setTimezone('Europe/Berlin')->format('Y-m-d H:i T'), $letter->body);
        $this->assertStringContainsString('Betrag der Rückbuchung: 89.00 EUR.', $letter->body);
        $this->assertStringContainsString('Eröffnet: ', $letter->body);
        $this->assertStringContainsString('Erstatten Sie nicht zusätzlich', $letter->body);
    }

    public function test_the_outcome_closes_the_incident_and_an_older_snapshot_changes_nothing(): void
    {
        $this->paidOrder();
        $this->stripe['disputes'][] = $this->dispute('du_2', 'needs_response', 'fraudulent', now()->addDays(10)->getTimestamp());
        $integration = $this->connected();
        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->stripe['disputes'] = [$this->dispute('du_2', 'lost', 'fraudulent', null)];
        app(StripeSync::class)->run($integration);
        $this->drain();

        $incident = Incident::query()->where('family', DisputeIncidents::FAMILY)->firstOrFail();
        $this->assertSame([Incident::STATE_RESOLVED, 'auto_resolved_dispute_lost'], [$incident->state, $incident->resolution_reason]);
        $dispute = ProviderDispute::query()->where('external_id', 'du_2')->firstOrFail();
        $this->assertSame('lost', $dispute->status);
        $this->assertNotNull($dispute->closed_at);

        $lost = app(IncidentNotificationContentBuilder::class)->forIncident($incident, $this->store, NotificationDelivery::KIND_INCIDENT_RECOVERED, NotificationPreferences::fromArray([], 'ru', 'Europe/Berlin'));
        $this->assertSame('проигран, сумма списана в пользу покупателя', $lost['fact_params']['outcome']);

        $first = EventInbox::query()->where('event_type', EventInbox::EVENT_DISPUTE_SNAPSHOT)->orderBy('aggregate_revision')->firstOrFail()->payload;
        $first['event_id'] = (string) Str::uuid();
        app(EventIngestor::class)->ingest($integration, [$first], null, (string) Str::uuid());
        $this->drain();

        $this->assertSame('lost', ProviderDispute::query()->where('external_id', 'du_2')->value('status'));
        $this->assertSame(Incident::STATE_RESOLVED, $incident->refresh()->state);
    }

    public function test_an_already_closed_dispute_seen_for_the_first_time_opens_no_incident(): void
    {
        $this->paidOrder();
        $this->stripe['disputes'][] = $this->dispute('du_old', 'won', 'duplicate', null);
        $integration = $this->connected();

        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame('won', ProviderDispute::query()->where('external_id', 'du_old')->value('status'));
        $this->assertFalse(Incident::query()->where('family', DisputeIncidents::FAMILY)->exists());
    }

    public function test_a_key_without_the_disputes_permission_keeps_syncing_payments(): void
    {
        $this->paidOrder();
        $integration = $this->connected();
        $this->failures['disputes'] = 403;

        $result = app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame('ok', $result['status']);
        $integration->refresh();
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->status);
        $this->assertSame('permission_missing', $integration->health['sync']['disputes']['state']);
        $this->assertNull($integration->health['last_error']);
        $this->assertTrue(FinancialTransaction::query()->where('external_operation_id', 'ch_1042')->exists());
    }

    public function test_a_reminder_is_requested_once_three_days_before_the_answer_is_due_and_amounts_follow_money_view(): void
    {
        $this->paidOrder();
        $this->stripe['disputes'][] = $this->dispute('du_3', 'warning_needs_response', 'general', now()->addDays(2)->getTimestamp());
        $this->stripe['disputes'][] = $this->dispute('du_4', 'needs_response', 'general', now()->addDays(9)->getTimestamp(), 'ch_other');
        $integration = $this->connected();
        app(StripeSync::class)->run($integration);
        $this->drain();

        $incident = Incident::query()->where('family', DisputeIncidents::FAMILY)->where('title_code', DisputeIncidents::RULE_INQUIRY)->firstOrFail();
        $this->assertSame(1, app(DisputeIncidents::class)->remindDue());
        $this->assertSame(0, app(DisputeIncidents::class)->remindDue());
        $this->assertSame(1, DomainOutbox::query()->where('payload->incident_id', $incident->id)->where('payload->notification_kind', NotificationDelivery::KIND_INCIDENT_REMINDER)->count());

        $member = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'en']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id, 'role' => TenantRoles::VIEWER, 'permissions' => TenantPermissions::preset(TenantRoles::VIEWER, false)]);
        $this->actingAs($member)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('money_amounts_hidden', true)
            ->assertJsonPath('dispute.amount_minor', null)
            ->assertJsonPath('dispute.status', 'warning_needs_response');
    }

    public function test_a_dispute_webhook_feeds_the_same_path(): void
    {
        $this->paidOrder();
        $integration = $this->connected('whsec_0123456789abcdef');
        $payload = json_encode([
            'id' => 'evt_d1', 'object' => 'event', 'type' => 'charge.dispute.created', 'livemode' => false, 'created' => time(), 'api_version' => '2026-09-30.endive',
            'data' => ['object' => $this->dispute('du_5', 'needs_response', 'unrecognized', now()->addDays(14)->getTimestamp())],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_0123456789abcdef');

        $this->call('POST', "/api/v1/webhooks/stripe/{$integration->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)
            ->assertOk()->assertJsonPath('emitted', 1);
        $this->drain();

        $this->assertSame('unrecognized', ProviderDispute::query()->where('external_id', 'du_5')->value('reason'));
        $this->assertTrue(Incident::query()->where('family', DisputeIncidents::FAMILY)->exists());
    }

    private function connected(?string $webhookSecret = null): Integration
    {
        $id = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe", array_filter(['restricted_api_key' => self::KEY, 'webhook_secret' => $webhookSecret]))
            ->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function paidOrder(): Order
    {
        $this->stripe['payment_intents'][] = ['id' => 'pi_1042', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => 'ch_1042', 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDays(3)->getTimestamp(), 'amount' => 8900];
        $this->stripe['charges'][] = ['id' => 'ch_1042', 'object' => 'charge', 'payment_intent' => 'pi_1042', 'status' => 'succeeded', 'captured' => true, 'amount_captured' => 8900, 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDays(3)->getTimestamp()];

        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => '1042',
            'display_number' => '1042', 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'EUR',
            'currency_exponent' => 2, 'total_minor' => 8900, 'payment_expected' => true, 'paid_marked_at' => now()->subDays(3), 'transaction_ref' => 'pi_1042',
            'gateway' => 'stripe', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subDays(3), 'source_updated_at' => now()->subDays(3),
            'current_payload_hash' => hash('sha256', 'order-1042'), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispute(string $id, string $status, string $reason, ?int $dueBy, string $charge = 'ch_1042'): array
    {
        return [
            'id' => $id, 'object' => 'dispute', 'amount' => 8900, 'currency' => 'eur', 'charge' => $charge, 'payment_intent' => $charge === 'ch_1042' ? 'pi_1042' : null,
            'status' => $status, 'reason' => $reason, 'livemode' => false, 'created' => now()->subDay()->getTimestamp(),
            'evidence_details' => ['due_by' => $dueBy, 'has_evidence' => false, 'past_due' => false, 'submission_count' => 0],
            'metadata' => ['customer_email' => 'buyer@example.test'],
        ];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
