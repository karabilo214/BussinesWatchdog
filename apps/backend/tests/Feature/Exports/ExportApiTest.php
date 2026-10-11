<?php

namespace Tests\Feature\Exports;

use App\Models\ExportFile;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_create_and_download_a_findings_csv_export(): void
    {
        Storage::fake('artifacts');
        $context = $this->context('viewer');
        $this->finding($context, '=1001');

        $created = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/exports", [
                'kind' => ExportFile::KIND_FINDINGS,
                'format' => ExportFile::FORMAT_CSV,
                'filters' => ['status' => ReconciliationFinding::STATUS_MISMATCH],
            ])
            ->assertAccepted()
            ->assertJsonPath('kind', ExportFile::KIND_FINDINGS)
            ->assertJsonPath('state', ExportFile::STATE_READY);

        $exportId = $created->json('id');
        $this->assertDatabaseHas('exports', [
            'id' => $exportId,
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'state' => ExportFile::STATE_READY,
        ]);

        $download = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->get("/api/v1/exports/{$exportId}/download")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $csv = $download->streamedContent();
        $this->assertStringContainsString('finding_id,evaluated_at,status,rule_code', $csv);
        $this->assertStringContainsString(ReconciliationFinding::RULE_CAPTURE_AMOUNT, $csv);
        $this->assertStringContainsString("'=1001", $csv);
    }

    public function test_incident_export_is_scoped_to_the_active_tenant_and_store(): void
    {
        Storage::fake('artifacts');
        $context = $this->context('admin');
        $foreign = $this->context('admin');
        $this->incident($context, 'money/capture');
        $this->incident($foreign, 'money/foreign');

        $created = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/exports", [
                'kind' => ExportFile::KIND_INCIDENTS,
                'format' => ExportFile::FORMAT_CSV,
                'filters' => ['state' => [Incident::STATE_OPEN]],
            ])
            ->assertAccepted();

        $csv = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->get("/api/v1/exports/{$created->json('id')}/download")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('money/capture', $csv);
        $this->assertStringNotContainsString('money/foreign', $csv);

        $this->actingAs($foreign['user'])
            ->withSession(['active_tenant_id' => $foreign['tenant']->id])
            ->getJson("/api/v1/exports/{$created->json('id')}")
            ->assertNotFound();
    }

    public function test_export_rejects_foreign_store_and_invalid_kind(): void
    {
        $context = $this->context('admin');
        $foreign = $this->context('admin');

        $this->actingAs($foreign['user'])
            ->withSession(['active_tenant_id' => $foreign['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/exports", [
                'kind' => ExportFile::KIND_FINDINGS,
                'format' => ExportFile::FORMAT_CSV,
            ])
            ->assertNotFound();

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/exports", [
                'kind' => 'payments',
                'format' => ExportFile::FORMAT_CSV,
            ])
            ->assertUnprocessable();
    }

    public function test_transaction_export_is_scoped_and_keeps_amounts_as_minor_units(): void
    {
        Storage::fake('artifacts');
        $context = $this->context('admin');
        $foreign = $this->context('admin');
        $this->transaction($context, '=cap_100', 'capture', 'succeeded', 18400);
        $this->transaction($context, 'ref_100', 'refund', 'succeeded', 400);
        $this->transaction($foreign, 'cap_foreign', 'capture', 'succeeded', 999);

        $created = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/exports", [
                'kind' => ExportFile::KIND_TRANSACTIONS,
                'format' => ExportFile::FORMAT_CSV,
                'filters' => ['kind' => 'capture', 'currency' => 'eur'],
            ])
            ->assertAccepted()
            ->assertJsonPath('kind', ExportFile::KIND_TRANSACTIONS);

        $csv = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->get("/api/v1/exports/{$created->json('id')}/download")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('transaction_id,occurred_at,payment_id,payment_external_id', $csv);
        $this->assertStringContainsString("'=cap_100", $csv);
        $this->assertStringContainsString(',capture,succeeded,EUR,2,18400,independent_provider', $csv);
        $this->assertStringNotContainsString('ref_100', $csv);
        $this->assertStringNotContainsString('cap_foreign', $csv);
    }

    public function test_expired_exports_are_purged_from_storage(): void
    {
        $disk = Storage::fake('artifacts');
        $context = $this->context('admin');
        $expired = $this->exportFile($context, 'expired.csv', now()->subMinute());
        $fresh = $this->exportFile($context, 'fresh.csv', now()->addDay());
        $disk->put($expired->object_key, 'expired');
        $disk->put($fresh->object_key, 'fresh');

        $this->artisan('exports:purge')->assertSuccessful();

        $disk->assertMissing($expired->object_key);
        $disk->assertExists($fresh->object_key);
        $this->assertSame(ExportFile::STATE_DELETED, $expired->fresh()->state);
        $this->assertSame(ExportFile::STATE_READY, $fresh->fresh()->state);
    }

    public function test_expired_export_does_not_advertise_a_download_url(): void
    {
        Storage::fake('artifacts');
        $context = $this->context('admin');
        $expired = $this->exportFile($context, 'expired.csv', now()->subMinute());

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/exports/{$expired->id}")
            ->assertOk()
            ->assertJsonPath('download_url', null);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->get("/api/v1/exports/{$expired->id}/download")
            ->assertNotFound();
    }

    public function test_exports_purge_command_is_scheduled(): void
    {
        $this->artisan('exports:purge')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'exports:purge'));
        $this->assertSame('25 3 * * *', $event?->expression);
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration, order: Order, payment: Payment}
     */
    private function context(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Export Tester',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant = Tenant::query()->create([
            'name' => fake()->company(),
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration = Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'stripe',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
        $order = Order::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'external_id' => fake()->uuid(),
            'display_number' => '#1001',
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 18400,
            'payment_expected' => true,
            'paid_marked_at' => now()->subMinutes(10),
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subMinutes(2),
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'external_id' => fake()->uuid(),
            'intent_ref' => 'pi_'.fake()->uuid(),
            'charge_ref' => 'ch_'.fake()->uuid(),
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'status' => 'captured',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('user', 'tenant', 'store', 'integration', 'order', 'payment');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function finding(array $context, string $displayNumber): void
    {
        $context['order']->forceFill(['display_number' => $displayNumber])->save();
        $run = ReconciliationRun::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'status' => 'completed',
            'algorithm_version' => 'test',
            'config_version' => 1,
            'currency' => 'EUR',
            'scope' => ['order_id' => $context['order']->id],
            'coverage_snapshot' => [],
            'counters' => ['findings' => 1],
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
        ]);
        ReconciliationFinding::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'run_id' => $run->id,
            'order_id' => $context['order']->id,
            'payment_id' => $context['payment']->id,
            'rule_code' => ReconciliationFinding::RULE_CAPTURE_AMOUNT,
            'status' => ReconciliationFinding::STATUS_MISMATCH,
            'reason_code' => 'capture_amount_differs',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'expected_minor' => 18400,
            'actual_minor' => 17000,
            'difference_minor' => -1400,
            'evidence' => [],
            'config_snapshot' => [],
            'evaluated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function incident(array $context, string $component): void
    {
        Incident::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'family' => 'money',
            'component' => $component,
            'fingerprint' => 'money:'.$component,
            'state' => Incident::STATE_OPEN,
            'severity' => Incident::SEVERITY_WARNING,
            'title_code' => ReconciliationFinding::RULE_CAPTURE_AMOUNT,
            'currency' => 'EUR',
            'verified_discrepancy_minor' => 1400,
            'first_seen_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function transaction(array $context, string $externalOperationId, string $kind, string $status, int $amountMinor): void
    {
        FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $context['payment']->id,
            'external_operation_id' => $externalOperationId,
            'kind' => $kind,
            'status' => $status,
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => $amountMinor,
            'occurred_at' => now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', $externalOperationId),
            'metadata' => [],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function exportFile(array $context, string $file, Carbon $expiresAt): ExportFile
    {
        return ExportFile::query()->forceCreate([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'created_by' => $context['user']->id,
            'kind' => ExportFile::KIND_FINDINGS,
            'format' => ExportFile::FORMAT_CSV,
            'filters' => [],
            'state' => ExportFile::STATE_READY,
            'object_key' => implode('/', ['tenants', $context['tenant']->id, 'stores', $context['store']->id, 'exports', $file]),
            'content_type' => 'text/csv; charset=UTF-8',
            'size_bytes' => 5,
            'sha256' => hash('sha256', $file),
            'expires_at' => $expiresAt,
            'completed_at' => now(),
            'created_at' => now(),
        ]);
    }
}
