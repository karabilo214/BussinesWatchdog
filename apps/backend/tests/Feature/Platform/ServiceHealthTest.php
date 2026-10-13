<?php

namespace Tests\Feature\Platform;

use App\Models\CheckScenario;
use App\Models\DomainOutbox;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Health\ReadinessChecks;
use App\Support\Security\Keyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ServiceHealthTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.release', '2026.10.20-1');
        $this->app->instance(ReadinessChecks::class, new class(app(Keyring::class)) extends ReadinessChecks
        {
            public function all(): array
            {
                return ['database' => ['ok' => true], 'redis' => ['ok' => false], 'cache' => ['ok' => true], 'keyring' => ['ok' => true]];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_service_summary_shows_components_queue_ages_problems_and_versions_without_customer_data(): void
    {
        $context = $this->context();
        $context['tenant']->forceFill(['name' => 'Kaffee Geheim GmbH'])->save();
        $this->outbox($context, DomainOutbox::STATUS_PENDING, now()->subMinutes(20));
        $this->outbox($context, DomainOutbox::STATUS_DEAD_LETTER, now()->subDays(2));
        $scenario = CheckScenario::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM, 'version' => 1, 'enabled' => true,
            'adapter_version' => ScenarioDefinition::ADAPTER_VERSION, 'definition' => ['product_url' => $context['store']->base_url.'/p'], 'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = (string) Str::uuid7();
        DB::table('check_runs')->insert(['id' => $run, 'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'scenario_id' => $scenario->id, 'scenario_version' => 1, 'trigger' => 'scheduled', 'dedupe_key' => 'run-'.$run, 'status' => 'queued', 'config_snapshot' => '{}', 'scheduled_at' => now()->subMinutes(5), 'next_attempt_at' => now(), 'created_at' => now()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->subHours(2)->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHour()]);
        $context['integration']->forceFill(['status' => 'active', 'last_successful_sync_at' => now()->subDays(2)])->save();

        $this->signIn(PlatformStaff::ROLE_ADMIN);
        $response = $this->getJson('/platform-api/v1/health')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $health = $response->json();

        $this->assertSame('crit', $health['state']);
        $this->assertSame(['database' => 'ok', 'redis' => 'crit', 'cache' => 'ok', 'keyring' => 'ok'], $health['components']);
        $this->assertSame(['waiting' => 1, 'oldest_age_seconds' => 1260, 'problems' => 1, 'state' => 'warn'], $health['queues']['domain_outbox']);
        $this->assertSame(['waiting' => 1, 'oldest_age_seconds' => 7260, 'problems' => 1, 'state' => 'crit'], $health['queues']['jobs']);
        $this->assertSame('ok', $health['queues']['event_inbox']['state']);
        $this->assertNull($health['queues']['billing_events']['last_received_at']);
        $this->assertSame(1, $health['browser_checks']['waiting']);
        $this->assertSame(0, $health['browser_checks']['workers_seen']);
        $this->assertSame('crit', $health['browser_checks']['state']);
        $this->assertSame(['active' => 1, 'stale' => 1, 'state' => 'warn'], $health['connector_sync']);
        $this->assertSame('2026.10.20-1', $health['version']['app']);
        $this->assertStringNotContainsString('Kaffee Geheim', (string) $response->getContent());
        $this->assertStringNotContainsString($context['tenant']->id, (string) $response->getContent());
    }

    public function test_support_and_billing_staff_do_not_see_the_service_summary(): void
    {
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson('/platform-api/v1/health')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function outbox(array $context, string $status, Carbon $createdAt): void
    {
        DomainOutbox::query()->create([
            'tenant_id' => $context['tenant']->id, 'topic' => DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED, 'dedupe_key' => (string) Str::uuid(), 'payload' => ['event_inbox_id' => (string) Str::uuid()],
            'status' => $status, 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => $createdAt,
        ]);
    }

    private function signIn(string $role): void
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
