<?php

namespace Tests\Feature\Platform;

use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\PlatformStaff;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\TrialStoreHosts;
use App\Support\Browser\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class TenantStoreDataTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.billing.enforcement', 'pilot');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_reads_findings_orders_checks_with_screenshots_and_incidents_of_a_customer_store(): void
    {
        $disk = Storage::fake('artifacts');
        $disk->buildTemporaryUrlsUsing(fn (string $path, $expiration): string => 'https://objects.example.test/'.$path.'?expires='.$expiration->getTimestamp());
        $context = $this->context();
        $other = $this->context();
        $data = $this->storeData($context);
        $foreign = $this->storeData($other);
        $tenant = $context['tenant']->id;
        $store = $context['store']->id;
        $this->signIn();

        $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}")->assertOk()->assertJsonPath('id', $store)->assertJsonPath('name', 'Main Shop');
        $this->assertSame(0, DB::table('platform_audit_log')->where('target_tenant_id', $tenant)->count(), 'Views are not audited.');

        $findings = $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/findings")->assertOk()->json('data');
        $this->assertSame([$data['finding']], array_column($findings, 'id'));

        $orders = $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/orders?limit=1")->assertOk();
        $this->assertSame([$data['newer_order']], array_column($orders->json('data'), 'id'));
        $this->assertSame('18400', $orders->json('data.0.total_minor'));
        $next = $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/orders?limit=1&cursor=".urlencode((string) $orders->json('next_cursor')))->assertOk();
        $this->assertSame([$data['order']], array_column($next->json('data'), 'id'));
        $this->assertNull($next->json('next_cursor'));
        $this->getJson("/platform-api/v1/tenants/{$tenant}/orders/{$data['order']}")->assertOk()->assertJsonPath('id', $data['order']);

        $checks = $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/checks")->assertOk()->json('data');
        $this->assertSame([$data['run']], array_column($checks, 'id'));
        $detail = $this->getJson("/platform-api/v1/tenants/{$tenant}/checks/{$data['run']}")->assertOk();
        $this->assertSame($data['artifact'], $detail->json('attempts.0.artifacts.0.id'));
        $this->assertArrayNotHasKey('object_key', $detail->json('attempts.0.artifacts.0'));
        $url = $this->getJson("/platform-api/v1/tenants/{$tenant}/artifacts/{$data['artifact']}/download")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('https://objects.example.test/', $url->json('url'));

        $incidents = $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/incidents")->assertOk()->json('data');
        $this->assertSame([$data['incident']], array_column($incidents, 'id'));
        $this->getJson("/platform-api/v1/tenants/{$tenant}/incidents/{$data['incident']}")->assertOk()->assertJsonPath('id', $data['incident']);

        $otherStore = $other['store']->id;
        $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$otherStore}")->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/{$otherStore}/findings")->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/orders/{$foreign['order']}")->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/checks/{$foreign['run']}")->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/artifacts/{$foreign['artifact']}/download")->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/incidents/{$foreign['incident']}")->assertNotFound();
        $this->getJson('/platform-api/v1/tenants/'.Str::uuid().'/stores/'.$store)->assertNotFound();
        $this->getJson("/platform-api/v1/tenants/{$tenant}/stores/not-a-uuid")->assertNotFound();
    }

    public function test_owner_adds_a_store_for_the_customer_with_a_reason_within_the_plan(): void
    {
        $context = $this->context();
        $tenant = $context['tenant']->id;
        $this->signIn();
        $input = ['name' => 'Second Shop', 'base_url' => 'https://second-shop.example', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'locale' => 'de', 'platform' => 'shopware'];

        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores", $input)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores", ['reason' => 'Customer asked by phone'] + array_merge($input, ['base_url' => 'http://insecure.example']))->assertStatus(422);
        $created = $this->postJson("/platform-api/v1/tenants/{$tenant}/stores", ['reason' => 'Customer asked by phone'] + $input)->assertCreated()->assertJsonPath('name', 'Second Shop')->assertJsonPath('platform', 'shopware')->json();

        $this->assertDatabaseHas('stores', ['id' => $created['id'], 'tenant_id' => $tenant, 'base_url' => 'https://second-shop.example']);
        $audit = DB::table('platform_audit_log')->where('action', 'tenant.store_created')->sole();
        $this->assertSame($tenant, $audit->target_tenant_id);
        $this->assertSame('Customer asked by phone', $audit->reason);

        Subscription::query()->create([
            'tenant_id' => $tenant, 'provider' => 'internal', 'plan_code' => 'start', 'entitlements' => FeatureCatalog::normalize(['features' => [], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 2]]),
            'extra_store_quantity' => 0, 'status' => Subscription::STATUS_ACTIVE, 'period_start' => now()->subDay(), 'period_end' => now()->addMonth(), 'version' => 1,
        ]);
        config()->set('watchdog.billing.enforcement', 'enforce');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores", ['reason' => 'One more'] + array_merge($input, ['base_url' => 'https://third.example']))->assertForbidden()->assertJsonPath('code', 'plan_store_limit_reached');
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'tenant.store_created')->count());
    }

    public function test_only_platform_owner_can_delete_one_store_with_its_history_and_files(): void
    {
        Storage::fake('artifacts');
        Storage::fake('exports');
        config()->set('watchdog.exports.disk', 'exports');
        $context = $this->context();
        $other = $this->context();
        $data = $this->storeData($context);
        $foreign = $this->storeData($other);
        $tenant = $context['tenant']->id;
        $store = $context['store']->id;
        $path = "tenants/{$tenant}/stores/{$store}/checks/{$data['artifact']}.jpg";
        Storage::disk('artifacts')->put($path, 'screenshot');
        $exportPath = "tenants/{$tenant}/stores/{$store}/exports/test.csv";
        Storage::disk('exports')->put($exportPath, "id\n1\n");
        DB::table('exports')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'store_id' => $store, 'kind' => 'findings', 'format' => 'csv',
            'filters' => '{}', 'state' => 'ready', 'object_key' => $exportPath, 'created_at' => now(),
        ]);
        DB::table('domain_outbox')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'topic' => 'event_inbox.received', 'dedupe_key' => (string) Str::uuid(),
            'payload' => json_encode(['store_id' => $store, 'event_inbox_id' => (string) Str::uuid()]), 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(),
        ]);
        DB::table('trial_store_hosts')->insert(['host' => 'shop.example', 'tenant_id' => $tenant, 'store_id' => $store, 'claimed_at' => now()]);
        $url = "/platform-api/v1/tenants/{$tenant}/stores/{$store}/delete";
        $this->signIn();

        $this->postJson($url, ['name' => 'Main Shop', 'reason' => 'Customer request'])->assertStatus(422)->assertJsonValidationErrors('confirmation_code');
        $this->postJson($url, ['name' => 'Wrong Shop', 'reason' => 'Customer request', 'confirmation_code' => $this->code()])->assertStatus(422)->assertJsonPath('code', 'store_name_mismatch');
        $this->postJson($url, ['name' => 'Main Shop', 'reason' => 'Customer request', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->assertDatabaseHas('stores', ['id' => $store]);
        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores/{$other['store']->id}/delete", ['name' => $other['store']->name, 'reason' => 'Wrong company', 'confirmation_code' => $this->code()])->assertNotFound();
        $this->postJson($url, ['name' => 'Main Shop', 'reason' => 'Customer request', 'confirmation_code' => $this->code()])->assertOk()->assertJsonPath('status', 'deleted');

        Storage::disk('artifacts')->assertMissing($path);
        Storage::disk('exports')->assertMissing($exportPath);
        $this->assertDatabaseMissing('stores', ['id' => $store]);
        $this->assertSame(0, DB::table('domain_outbox')->where('tenant_id', $tenant)->where('payload->store_id', $store)->count());
        $this->assertDatabaseMissing('trial_store_hosts', ['host' => 'shop.example']);
        $this->assertDatabaseHas('trial_store_hosts', ['host' => TrialStoreHosts::hashed('shop.example'), 'tenant_id' => $tenant]);
        foreach (['orders', 'reconciliation_findings', 'incidents', 'check_runs', 'artifacts', 'integrations', 'audit_log'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $tenant)->where('store_id', $store)->count(), $table);
        }
        $this->assertDatabaseHas('orders', ['id' => $foreign['order'], 'store_id' => $other['store']->id]);
        $this->assertSame('Customer request', DB::table('platform_audit_log')->where('action', 'tenant.store_deleted')->where('target_id', $store)->where('result', 'succeeded')->value('reason'));
        $this->assertDatabaseHas('store_deletion_journal', ['tenant_id' => $tenant, 'store_id' => $store]);

        $output = new BufferedOutput;
        Artisan::call('privacy:deletion-journal', [], $output);
        $journal = tempnam(sys_get_temp_dir(), 'bw-store-journal-');
        file_put_contents($journal, $output->fetch());
        $this->assertStringContainsString('"kind":"store"', (string) file_get_contents($journal));
        (new Store)->forceFill([
            'id' => $store, 'tenant_id' => $tenant, 'name' => 'Main Shop', 'base_url' => 'https://restored.example',
            'platform' => 'woocommerce', 'timezone' => 'Europe/Berlin', 'locale' => 'de', 'default_currency' => 'EUR',
        ])->save();
        Artisan::call('privacy:replay-deletions', ['journal' => $journal]);
        unlink($journal);
        $this->assertDatabaseMissing('stores', ['id' => $store]);
    }

    public function test_only_staff_who_manage_customers_see_store_data(): void
    {
        $context = $this->context();
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores/{$context['store']->id}")->assertForbidden();
        $this->getJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores/{$context['store']->id}/orders")->assertForbidden();
        $this->postJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores/{$context['store']->id}/delete", ['name' => 'Main Shop', 'reason' => 'Try', 'confirmation_code' => '000000'])->assertForbidden();

        PlatformStaff::query()->where('role', PlatformStaff::ROLE_MEMBER)->firstOrFail()->forceFill(['capabilities' => ['tenants.read', 'tenants.manage']])->save();
        $this->postJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores/{$context['store']->id}/delete", ['name' => 'Main Shop', 'reason' => 'Try', 'confirmation_code' => $this->code()])->assertForbidden();
        $this->assertDatabaseHas('stores', ['id' => $context['store']->id]);
    }

    public function test_support_reruns_a_check_and_handles_an_incident_for_the_customer_without_using_their_allowance(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active', 'browser_enabled' => true, 'verified_at' => now()])->save();
        $context['tenant']->forceFill(['status' => 'active'])->save();
        $data = $this->storeData($context);
        $foreign = $this->storeData($this->context());
        $tenant = $context['tenant']->id;
        $store = $context['store']->id;
        $scenario = CheckScenario::query()->where('store_id', $store)->value('id');
        $this->signIn(PlatformStaff::ROLE_SUPPORT);

        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/checks", ['scenario_id' => $scenario])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/checks", ['scenario_id' => CheckScenario::query()->where('store_id', '!=', $store)->value('id'), 'reason' => 'Customer asked'])->assertNotFound();
        $run = $this->postJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/checks", ['scenario_id' => $scenario, 'reason' => 'Customer asked by phone'])->assertStatus(202)->assertJsonPath('trigger', 'support')->json();
        $this->assertSame(0, DB::table('usage_entries')->count(), 'A support rerun uses none of the customer allowance.');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/stores/{$store}/checks", ['scenario_id' => $scenario, 'reason' => 'Again'])->assertStatus(429);
        $this->assertSame('Customer asked by phone', DB::table('platform_audit_log')->where('action', 'tenant.check_rerun')->where('target_id', $run['run_id'])->value('reason'));
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant, 'action' => 'check_run.support_started', 'entity_id' => $run['run_id'], 'actor_type' => 'system']);

        $this->postJson("/platform-api/v1/tenants/{$tenant}/incidents/{$foreign['incident']}/acknowledge", ['reason' => 'Wrong tenant'])->assertNotFound();
        $this->postJson("/platform-api/v1/tenants/{$tenant}/incidents/{$data['incident']}/acknowledge", ['reason' => 'Looking into it'])->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/incidents/{$data['incident']}/resolve", ['reason' => 'Fixed with the customer'])->assertOk()->assertJsonPath('state', 'resolved');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/incidents/{$data['incident']}/resolve", ['reason' => 'Twice'])->assertStatus(409);
        $activity = DB::table('incident_activity')->where('incident_id', $data['incident'])->where('kind', 'resolved')->first();
        $this->assertNull($activity->actor_id);
        $this->assertEqualsCanonicalizing(['reason' => 'Fixed with the customer', 'by' => 'platform'], json_decode((string) $activity->sanitized_data, true));
        $this->assertSame(['tenant.incident_acknowledged', 'tenant.incident_resolved'], DB::table('platform_audit_log')->where('target_id', $data['incident'])->orderBy('created_at')->pluck('action')->all());
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant, 'action' => 'incident.resolved_by_platform', 'entity_id' => $data['incident']]);
    }

    public function test_support_actions_need_their_own_permission(): void
    {
        $context = $this->context();
        $data = $this->storeData($context);
        $this->signIn(PlatformStaff::ROLE_BILLING);

        $this->postJson("/platform-api/v1/tenants/{$context['tenant']->id}/stores/{$context['store']->id}/checks", ['scenario_id' => (string) Str::uuid(), 'reason' => 'Try'])->assertForbidden();
        $this->postJson("/platform-api/v1/tenants/{$context['tenant']->id}/incidents/{$data['incident']}/resolve", ['reason' => 'Try'])->assertForbidden();
        $this->assertSame('open', Incident::query()->whereKey($data['incident'])->value('state'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{order: string, newer_order: string, finding: string, run: string, artifact: string, incident: string}
     */
    private function storeData(array $context): array
    {
        $tenantId = $context['tenant']->id;
        $storeId = $context['store']->id;
        $order = $this->order($context, ['created_at' => now()->subMinute()]);
        $newer = $this->order($context, ['display_number' => '#15239']);
        $run = ReconciliationRun::query()->create(['tenant_id' => $tenantId, 'store_id' => $storeId, 'status' => 'completed', 'algorithm_version' => 'v0', 'config_version' => 1, 'scope' => ['order_id' => $order->id], 'coverage_snapshot' => [], 'counters' => [], 'created_at' => now()]);
        $finding = ReconciliationFinding::query()->create(['tenant_id' => $tenantId, 'store_id' => $storeId, 'run_id' => $run->id, 'order_id' => $order->id, 'rule_code' => 'MONEY_CAPTURE_MISSING', 'status' => 'mismatch', 'reason_code' => 'x', 'evidence' => [], 'config_snapshot' => [], 'evaluated_at' => now()]);
        $scenario = CheckScenario::query()->create([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1, 'enabled' => true, 'adapter_version' => ScenarioDefinition::ADAPTER_VERSION, 'definition' => ['product_url' => $context['store']->base_url.'/product/x'],
            'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $checkRun = (string) Str::uuid7();
        DB::table('check_runs')->insert([
            'id' => $checkRun, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'scenario_id' => $scenario->id, 'scenario_version' => 1,
            'trigger' => 'scheduled', 'dedupe_key' => 'run-'.$checkRun, 'status' => 'failed', 'config_snapshot' => '{}', 'scheduled_at' => now(),
            'next_attempt_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);
        $worker = (string) Str::uuid7();
        DB::table('browser_workers')->insert(['id' => $worker, 'name' => 'worker-'.$worker, 'token_hash' => hash('sha256', $worker), 'created_at' => now()]);
        $attempt = (string) Str::uuid7();
        DB::table('check_attempts')->insert([
            'id' => $attempt, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'run_id' => $checkRun, 'attempt_number' => 1, 'worker_id' => $worker, 'fencing_token' => 1,
            'lease_token_hash' => hash('sha256', $attempt), 'lease_until' => now(), 'absolute_deadline_at' => now(), 'status' => 'failed', 'browser_version' => 'chromium',
            'location' => 'eu', 'started_at' => now(), 'finished_at' => now(),
        ]);
        $artifact = (string) Str::uuid7();
        DB::table('artifacts')->insert([
            'id' => $artifact, 'tenant_id' => $tenantId, 'store_id' => $storeId, 'attempt_id' => $attempt, 'kind' => 'screenshot', 'object_key' => "tenants/{$tenantId}/stores/{$storeId}/checks/{$artifact}.jpg",
            'content_type' => 'image/jpeg', 'size_bytes' => 1234, 'sha256' => hash('sha256', $artifact), 'redaction_version' => 'v1', 'state' => 'ready', 'expires_at' => now()->addDays(30), 'created_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'family' => 'money', 'component' => 'capture', 'fingerprint' => Str::random(20),
            'state' => 'open', 'severity' => Incident::SEVERITY_WARNING, 'title_code' => 'MONEY_CAPTURE_MISSING', 'currency' => 'EUR',
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(), 'first_bad_at' => now()->subDay(), 'revision' => 1, 'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);

        return ['order' => $order->id, 'newer_order' => $newer->id, 'finding' => $finding->id, 'run' => $checkRun, 'artifact' => $artifact, 'incident' => $incident->id];
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
