<?php

namespace Tests\Feature\Privacy;

use App\Mail\NotificationMessageMail;
use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\DataDeletionRequest;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\StoreCapacity;
use App\Support\Billing\TrialStoreHosts;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Privacy\OrganizationPurger;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class OrganizationDeletionTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        Mail::fake();
        Storage::fake((string) config('watchdog.artifacts.disk'));
        Storage::fake((string) config('watchdog.exports.disk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_tenant_table_is_either_purged_or_explicitly_kept(): void
    {
        $tenantTables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
        $classified = collect([...OrganizationPurger::PURGED_TABLES, ...OrganizationPurger::KEPT_TABLES])->sort()->values()->all();

        $this->assertSame($tenantTables, $classified, 'A table with tenant_id must be added to OrganizationPurger::PURGED_TABLES or KEPT_TABLES (ADR 0069).');
    }

    public function test_only_the_owner_requests_deletion_with_step_up_and_the_exact_name(): void
    {
        $context = $this->context();
        $admin = $this->context('admin', $context['tenant'])['user'];

        $this->asMember($admin, $context['tenant'])->postJson('/api/v1/organization-deletion', $this->confirmation($context['tenant']))->assertStatus(403);
        $this->asMember($context['user'], $context['tenant'])->postJson('/api/v1/organization-deletion', ['organization_name' => 'Wrong', 'current_password' => 'very-secure-password'])
            ->assertStatus(422)->assertJsonPath('code', 'organization_name_mismatch');
        $this->asMember($context['user'], $context['tenant'])->postJson('/api/v1/organization-deletion', ['organization_name' => $context['tenant']->name, 'current_password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame('active', $context['tenant']->fresh()->status);
        $this->assertSame(0, DataDeletionRequest::query()->count());
    }

    public function test_a_request_stops_the_service_at_once_and_the_owner_can_cancel_within_thirty_days(): void
    {
        $context = $this->context();
        $tenant = $context['tenant'];

        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))
            ->assertStatus(202)
            ->assertJsonPath('tenant_status', 'deleting')
            ->assertJsonPath('request.status', DataDeletionRequest::STATUS_REQUESTED)
            ->assertJsonPath('request.purge_after', '2026-11-19T10:00:00.000000Z')
            ->assertJsonPath('request.can_cancel', true);

        $this->asMember($context['user'], $tenant)->getJson('/api/v1/overview')->assertStatus(403)->assertJsonPath('code', 'tenant_forbidden');
        $this->asMember($context['user'], $tenant)->getJson('/api/v1/auth/me')
            ->assertJsonPath('memberships.0.tenant_status', 'deleting')
            ->assertJsonPath('memberships.0.deletion_purge_after', '2026-11-19T10:00:00.000000Z');
        $this->assertTrue(StoreCapacity::paused($context['integration']));
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant->id, 'action' => 'organization.deletion_requested']);
        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))->assertStatus(409)->assertJsonPath('code', 'deletion_already_requested');

        Carbon::setTestNow(now()->addDays(29));
        $this->assertSame(0, app(OrganizationPurger::class)->processDue()['processed']);

        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion/cancel', ['current_password' => 'very-secure-password'])
            ->assertOk()->assertJsonPath('tenant_status', 'active')->assertJsonPath('request', null);

        $this->assertFalse(StoreCapacity::paused($context['integration']));
        $this->asMember($context['user'], $tenant)->getJson('/api/v1/overview')->assertOk();
        $this->assertSame(DataDeletionRequest::STATUS_CANCELLED, DataDeletionRequest::query()->value('status'));
        $subjects = $this->mailSubjects($context['user']->email);
        $this->assertCount(2, $subjects);
        $this->assertStringContainsString('2026-11-19', $subjects[0]);
        $this->assertStringContainsString($tenant->name, $subjects[1]);
    }

    public function test_after_thirty_days_the_data_is_purged_in_resumable_stages_keeping_billing_records_and_other_tenants(): void
    {
        $context = $this->context();
        $tenant = $context['tenant'];
        $onlyHere = $context['user'];
        $onlyHere->forceFill(['salutation' => 'ms', 'first_name' => 'Anna', 'company_name' => 'Anna Shop GmbH'])->save();
        $shared = $this->context('admin', $tenant)['user'];
        $other = $this->context();
        Membership::query()->create(['tenant_id' => $other['tenant']->id, 'user_id' => $shared->id, 'role' => 'viewer']);
        $files = $this->populate($context, 'shop.example.test');
        $this->populate($other, 'other.example.test');
        $otherCounts = $this->counts($other['tenant']->id);
        $hostHash = TrialStoreHosts::hashed('shop.example.test');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        Http::fake(fn () => Http::response(['data' => ['id' => 'sub_del', 'status' => 'canceled']]));

        $this->asMember($onlyHere, $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))->assertStatus(202);
        Carbon::setTestNow(now()->addDays(30)->addMinute());

        $purger = app(OrganizationPurger::class);
        $runs = 0;

        do {
            $purger->processDue(5, 3);
            $runs++;
        } while (DataDeletionRequest::query()->value('status') !== DataDeletionRequest::STATUS_COMPLETED && $runs < 100);

        $this->assertGreaterThan(3, $runs);
        $this->assertSame(DataDeletionRequest::STATUS_COMPLETED, DataDeletionRequest::query()->value('status'));

        foreach (OrganizationPurger::PURGED_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $tenant->id)->count(), $table.' still has rows of the deleted tenant');
        }

        foreach ($files as [$disk, $key]) {
            Storage::disk($disk)->assertMissing($key);
        }

        $this->assertSame(['name' => 'Deleted organization', 'status' => 'deleted'], Tenant::query()->whereKey($tenant->id)->first(['name', 'status'])->only(['name', 'status']));
        $this->assertSame(1, DB::table('billing_transactions')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DB::table('billing_subscription_contracts')->where('tenant_id', $tenant->id)->count());
        $this->assertSame([$hostHash], DB::table('trial_store_hosts')->where('tenant_id', $tenant->id)->pluck('host')->all());
        $this->assertTrue(app(TrialStoreHosts::class)->claimedByOther($other['tenant']->id, ['shop.example.test']));
        $this->assertDatabaseHas('data_deletion_journal', ['tenant_id' => $tenant->id, 'requested_via' => 'owner']);

        $anonymized = $onlyHere->fresh();
        $this->assertSame('deleted-'.$onlyHere->id.'@deleted.invalid', $anonymized->email);
        $this->assertSame('Deleted user', $anonymized->name);
        $this->assertSame([null, null, 'Deleted user', null], [$anonymized->salutation, $anonymized->first_name, $anonymized->last_name, $anonymized->company_name]);
        $this->assertNotNull($anonymized->disabled_at);
        $this->assertSame($shared->email, $shared->fresh()->email);

        $this->assertSame($otherCounts, $this->counts($other['tenant']->id));
        $this->assertCount(2, $this->mailSubjects($onlyHere->email));
        $this->assertSame([], $this->mailSubjects($shared->email));
    }

    /**
     * @return list<string>
     */
    private function mailSubjects(string $email): array
    {
        return Mail::sent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo($email))
            ->map(fn (NotificationMessageMail $mail): string => $mail->envelope()->subject)
            ->values()
            ->all();
    }

    public function test_a_cancellation_that_wins_the_race_stops_the_purge(): void
    {
        $context = $this->context();
        $this->asMember($context['user'], $context['tenant'])->postJson('/api/v1/organization-deletion', $this->confirmation($context['tenant']))->assertStatus(202);
        Carbon::setTestNow(now()->addDays(31));
        $this->asMember($context['user'], $context['tenant'])->postJson('/api/v1/organization-deletion/cancel', ['current_password' => 'very-secure-password'])->assertOk();

        DataDeletionRequest::query()->update(['status' => DataDeletionRequest::STATUS_CANCELLED]);
        $this->assertSame(0, app(OrganizationPurger::class)->processDue()['processed']);
        $this->assertSame('active', $context['tenant']->fresh()->status);
        $this->assertSame(1, Store::query()->where('tenant_id', $context['tenant']->id)->count());
    }

    public function test_the_journal_deletes_again_what_an_older_backup_brings_back(): void
    {
        $context = $this->context();
        $tenant = $context['tenant'];
        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))->assertStatus(202);
        Carbon::setTestNow(now()->addDays(31));
        app(OrganizationPurger::class)->processDue();
        $this->assertSame(DataDeletionRequest::STATUS_COMPLETED, DataDeletionRequest::query()->value('status'));

        $this->artisan('privacy:deletion-journal')->assertSuccessful();
        $journal = DB::table('data_deletion_journal')->get()->map(fn ($entry): string => json_encode((array) $entry))->implode("\n");
        $path = tempnam(sys_get_temp_dir(), 'journal');
        file_put_contents($path, $journal);

        DB::table('data_deletion_journal')->delete();
        DataDeletionRequest::query()->delete();
        Tenant::query()->whereKey($tenant->id)->update(['status' => 'active', 'name' => 'Restored from an old backup']);
        $user = User::query()->create(['name' => 'Back', 'email' => 'back@example.test', 'password_hash' => 'x', 'locale' => 'en']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
        Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Old', 'base_url' => 'https://old.example.test', 'timezone' => 'UTC', 'default_currency' => 'EUR']);

        $this->artisan('privacy:replay-deletions', ['journal' => $path])->assertSuccessful();
        unlink($path);

        $this->assertSame('deleted', $tenant->fresh()->status);
        $this->assertSame(0, Store::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Membership::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame('deleted-'.$user->id.'@deleted.invalid', $user->fresh()->email);
        $this->assertSame(1, DB::table('data_deletion_journal')->count());
    }

    public function test_only_the_platform_owner_deletes_on_request_with_a_code_and_can_cancel(): void
    {
        $context = $this->context();
        $tenant = $context['tenant'];

        $this->signIn('support@platform.test', PlatformStaff::ROLE_SUPPORT);
        $this->postJson("/platform-api/v1/tenants/{$tenant->id}/deletion", ['reason' => 'Customer asked', 'confirmation_code' => '000000'])->assertStatus(403);
        $this->postJson('/platform-api/v1/auth/logout')->assertSuccessful();

        $owner = $this->signIn('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $this->postJson("/platform-api/v1/tenants/{$tenant->id}/deletion", ['reason' => 'Customer asked', 'confirmation_code' => '000000'])
            ->assertStatus(403)->assertJsonPath('code', 'step_up_failed');
        $this->postJson("/platform-api/v1/tenants/{$tenant->id}/deletion", ['reason' => 'Customer asked by email', 'confirmation_code' => $this->code('owner@platform.test')])
            ->assertOk()
            ->assertJsonPath('status', 'deleting')
            ->assertJsonPath('deletion.requested_via', 'platform')
            ->assertJsonPath('deletion.can_cancel', true);

        $this->assertDatabaseHas('platform_audit_log', ['action' => 'tenant.deletion_requested', 'result' => 'succeeded', 'target_tenant_id' => $tenant->id, 'actor_staff_id' => $owner->id]);
        $this->assertSame($owner->user_id, DataDeletionRequest::query()->value('requested_by'));

        $this->postJson("/platform-api/v1/tenants/{$tenant->id}/deletion/cancel", ['reason' => 'Customer changed their mind', 'confirmation_code' => $this->code('owner@platform.test')])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('deletion', null);
    }

    public function test_a_paid_subscription_is_cancelled_first_and_a_refusal_changes_nothing(): void
    {
        $context = $this->context();
        $tenant = $context['tenant'];
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        $this->contract($tenant->id);
        $paddleUp = false;
        Http::fake(function (Request $request) use (&$paddleUp) {
            if (! $paddleUp) {
                return Http::response(['error' => ['code' => 'internal_error']], 500);
            }

            return str_ends_with($request->url(), '/subscriptions/sub_del/cancel')
                ? Http::response(['data' => ['id' => 'sub_del', 'status' => 'canceled']])
                : Http::response(['data' => []], 404);
        });

        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))
            ->assertStatus(503)->assertJsonPath('code', 'subscription_cancel_failed');
        $this->assertSame('active', $tenant->fresh()->status);

        $paddleUp = true;
        $this->asMember($context['user'], $tenant)->postJson('/api/v1/organization-deletion', $this->confirmation($tenant))->assertStatus(202);

        $cancel = collect(Http::recorded())->first(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/subscriptions/sub_del/cancel') && $pair[1]->status() === 200);
        $this->assertNotNull($cancel);
        $this->assertSame('immediately', $cancel[0]->data()['effective_from']);
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant->id, 'action' => 'billing.subscription_cancelled_for_deletion']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{string, string}>
     */
    private function populate(array $context, string $host): array
    {
        $tenantId = $context['tenant']->id;
        $storeId = $context['store']->id;
        DB::table('trial_store_hosts')->insert(['host' => $host, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'claimed_at' => now()]);

        $this->verifiedEmailChannel($context['tenant'], 'alerts-'.Str::random(4).'@example.test');
        $order = $this->order($context, ['paid_marked_at' => now()->subHours(2)]);
        app(MoneyIncidentCorrelator::class)->correlate(app(OrderReconciliationService::class)->evaluate($order));
        app(DomainOutboxDispatcher::class)->dispatchDue();

        $connector = Integration::query()->create([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(), 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '1.0.0', 'health' => [],
        ]);
        DB::table('integration_credentials')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'store_id' => $storeId, 'integration_id' => $connector->id, 'kind' => 'plugin_hmac', 'key_id' => Str::random(8), 'ciphertext' => 'x', 'key_version' => 1, 'fingerprint' => Str::random(16)]);
        DB::table('pairing_codes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'store_id' => $storeId, 'code_hash' => hash('sha256', Str::random()), 'created_by' => $context['user']->id, 'expires_at' => now()->addHour()]);
        DB::table('invitations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'email' => 'new-'.Str::random(4).'@example.test', 'role' => 'viewer', 'token_hash' => hash('sha256', Str::random()), 'invited_by' => $context['user']->id, 'expires_at' => now()->addDay()]);
        DB::table('usage_buckets')->insert(['tenant_id' => $tenantId, 'metric_key' => 'store_events', 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth()]);
        AuditLog::query()->create(['tenant_id' => $tenantId, 'actor_user_id' => $context['user']->id, 'actor_type' => 'user', 'action' => 'test', 'entity_type' => 'store', 'entity_id' => $storeId, 'changes' => [], 'request_id' => (string) Str::uuid(), 'created_at' => now()]);

        $workerId = (string) Str::uuid();
        DB::table('browser_workers')->insert(['id' => $workerId, 'name' => 'w-'.Str::random(6), 'token_hash' => hash('sha256', $workerId), 'created_at' => now()]);
        $scenarioId = (string) Str::uuid();
        DB::table('check_scenarios')->insert(['id' => $scenarioId, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'name' => 'Payment form', 'mode' => 'payment_form', 'version' => 1, 'enabled' => true, 'adapter_version' => 'x', 'definition' => '{}', 'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $runId = (string) Str::uuid7();
        DB::table('check_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'scenario_id' => $scenarioId, 'scenario_version' => 1, 'trigger' => 'scheduled', 'dedupe_key' => 'run-'.$runId, 'status' => 'passed', 'config_snapshot' => '{}', 'scheduled_at' => now(), 'next_attempt_at' => now(), 'finished_at' => now(), 'created_at' => now()]);
        $attemptId = (string) Str::uuid();
        DB::table('check_attempts')->insert(['id' => $attemptId, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'run_id' => $runId, 'attempt_number' => 1, 'worker_id' => $workerId, 'fencing_token' => 1, 'lease_token_hash' => hash('sha256', $attemptId), 'lease_until' => now(), 'absolute_deadline_at' => now(), 'status' => 'passed', 'browser_version' => '1', 'location' => 'eu', 'started_at' => now()]);

        $artifactKey = 'artifacts/'.$tenantId.'/shot.png';
        $exportKey = 'exports/'.$tenantId.'/findings.csv';
        Storage::disk((string) config('watchdog.artifacts.disk'))->put($artifactKey, 'png');
        Storage::disk((string) config('watchdog.exports.disk'))->put($exportKey, 'csv');
        DB::table('artifacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'store_id' => $storeId, 'attempt_id' => $attemptId, 'kind' => 'screenshot', 'object_key' => $artifactKey, 'content_type' => 'image/png', 'size_bytes' => 3, 'sha256' => hash('sha256', 'png'), 'redaction_version' => 'v1', 'state' => 'ready', 'expires_at' => now()->addDays(7), 'created_at' => now()]);
        DB::table('exports')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'store_id' => $storeId, 'kind' => 'findings', 'format' => 'csv', 'filters' => '{}', 'state' => 'ready', 'object_key' => $exportKey, 'created_at' => now()]);

        $this->contract($tenantId);

        return [[(string) config('watchdog.artifacts.disk'), $artifactKey], [(string) config('watchdog.exports.disk'), $exportKey]];
    }

    private function contract(string $tenantId): void
    {
        $plan = BillingPlan::query()->firstOrCreate(['code' => 'start'], ['state' => 'published']);
        $version = BillingPlanVersion::query()->firstOrCreate(['plan_id' => $plan->id, 'version' => 1], ['state' => 'published', 'localized_content' => ['name' => ['en' => 'Start']], 'entitlements' => ['features' => [], 'limits' => []]]);
        $price = BillingPrice::query()->firstOrCreate(['provider_price_ref' => 'pri_start'], ['plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'price_kind' => 'base', 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 1900, 'billing_interval' => 'month', 'tax_behavior' => 'provider_calculated', 'state' => 'published']);
        $ref = 'sub_'.(DB::table('billing_subscription_contracts')->count() === 0 ? 'del' : Str::random(6));
        $contract = BillingSubscriptionContract::query()->create([
            'tenant_id' => $tenantId, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_subscription_ref' => $ref, 'mode' => 'test',
            'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE, 'plan_version_id' => $version->id, 'price_id' => $price->id,
            'entitlement_snapshot' => ['features' => [], 'limits' => []], 'period_start' => now()->subDays(5), 'period_end' => now()->addDays(25), 'source_updated_at' => now(),
        ]);
        BillingTransaction::query()->create([
            'tenant_id' => $tenantId, 'contract_id' => $contract->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => 'test',
            'provider_transaction_ref' => 'txn_'.Str::random(6), 'provider_subscription_ref' => $ref, 'status' => 'completed', 'currency' => 'EUR', 'currency_exponent' => 2,
            'subtotal_minor' => 1597, 'discount_minor' => 0, 'tax_minor' => 303, 'total_minor' => 1900, 'fee_minor' => 133, 'earnings_minor' => 1767,
            'billed_at' => now()->subDays(5), 'source_updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(string $tenantId): array
    {
        return collect([...OrganizationPurger::PURGED_TABLES, ...OrganizationPurger::KEPT_TABLES])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('tenant_id', $tenantId)->count()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function confirmation(Tenant $tenant): array
    {
        return ['organization_name' => $tenant->name, 'current_password' => 'very-secure-password'];
    }

    private function asMember(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function signIn(string $email, string $role): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff '.$email, 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secrets[$email] = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code($email));
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code($email)])->assertOk();

        return $staff;
    }

    private function code(string $email): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secrets[$email], $totp->step(Carbon::now()->getTimestamp()));
    }
}
