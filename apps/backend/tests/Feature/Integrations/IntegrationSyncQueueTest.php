<?php

namespace Tests\Feature\Integrations;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Support\Integrations\IntegrationSyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class IntegrationSyncQueueTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_sync_commands_queue_one_job_per_eligible_integration_oldest_sync_first(): void
    {
        Queue::fake();
        $recent = $this->context()['integration'];
        $recent->forceFill(['last_successful_sync_at' => Carbon::now()->subMinutes(5)])->save();
        $old = $this->context()['integration'];
        $old->forceFill(['last_successful_sync_at' => Carbon::now()->subDay()])->save();
        $never = $this->context()['integration'];
        $disabled = $this->context()['integration'];
        $disabled->forceFill(['status' => Integration::STATUS_DISABLED])->save();
        $woo = $this->context()['integration'];
        $woo->forceFill(['provider' => 'woocommerce_api', 'status' => Integration::STATUS_DEGRADED])->save();

        $this->artisan('stripe:sync')->expectsOutput('stripe delta requested=3')->assertSuccessful();
        $this->artisan('stripe:sync --audit')->expectsOutput('stripe audit requested=3')->assertSuccessful();
        $this->artisan('woocommerce-api:sync --recent')->expectsOutput('woocommerce_api recent requested=1')->assertSuccessful();
        $this->artisan('paypal:sync --pointers')->expectsOutput('paypal pointers requested=0')->assertSuccessful();

        $pushed = Queue::pushed(SyncIntegration::class)->filter(fn (SyncIntegration $job): bool => $job->mode === 'delta')->values();
        $this->assertSame([$never->id, $old->id, $recent->id], $pushed->map(fn (SyncIntegration $job): string => $job->integrationId)->all());
        Queue::assertPushedOn(SyncIntegration::QUEUE, SyncIntegration::class);
        Queue::assertNotPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $disabled->id);
        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $woo->id && $job->mode === 'recent');
    }

    public function test_every_integration_is_requested_exactly_once_while_workers_update_sync_times(): void
    {
        $context = $this->context();
        $rows = [];
        $stores = [];

        foreach (range(1, 1200) as $index) {
            $storeId = (string) Str::uuid7();
            $stores[] = ['id' => $storeId, 'tenant_id' => $context['tenant']->id, 'name' => "Shop {$index}", 'base_url' => "https://shop-{$index}.example", 'timezone' => 'UTC', 'default_currency' => 'EUR', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()];
            $rows[] = [
                'id' => (string) Str::uuid7(), 'tenant_id' => $context['tenant']->id, 'store_id' => $storeId, 'provider' => 'stripe',
                'install_id' => (string) Str::uuid(), 'mode' => 'live', 'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                'status' => Integration::STATUS_ACTIVE, 'capabilities' => '[]', 'connector_version' => '1.0.0', 'health' => '{}',
                'last_successful_sync_at' => Carbon::now()->subMinutes(2000 - $index), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ];
        }

        foreach (array_chunk($stores, 300) as $chunk) {
            DB::table('stores')->insert($chunk);
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('integrations')->insert($chunk);
        }

        $synced = [];
        $this->app->instance(IntegrationSyncRunner::class, new class($synced) extends IntegrationSyncRunner
        {
            public function __construct(private array &$synced) {}

            public function run(Integration $integration, string $mode): array
            {
                $this->synced[] = $integration->id;
                $integration->forceFill(['last_successful_sync_at' => Carbon::now()])->save();

                return ['status' => 'ok', 'emitted' => 0, 'unchanged' => 0, 'complete' => true];
            }
        });

        $this->artisan('stripe:sync')->expectsOutput('stripe delta requested=1201')->assertSuccessful();

        $this->assertCount(1201, $synced);
        $this->assertCount(1201, array_unique($synced));
    }

    public function test_a_job_is_unique_per_integration_and_mode_and_never_overlaps_another_mode_of_the_same_integration(): void
    {
        $delta = new SyncIntegration('i-1', 'delta');
        $audit = new SyncIntegration('i-1', 'audit');

        $this->assertNotSame($delta->uniqueId(), $audit->uniqueId());
        $this->assertSame('i-1:delta', $delta->uniqueId());
        $this->assertGreaterThan($delta->timeout, (int) config('queue.connections.redis.retry_after'));
        [$deltaLock] = $delta->middleware();
        [$auditLock] = $audit->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $deltaLock);
        $this->assertSame($deltaLock->getLockKey($delta), $auditLock->getLockKey($audit));

        Queue::fake();
        SyncIntegration::dispatch('i-2', 'delta');
        SyncIntegration::dispatch('i-2', 'delta');
        SyncIntegration::dispatch('i-2', 'audit');
        Queue::assertPushed(SyncIntegration::class, 2);
    }

    public function test_the_job_syncs_through_the_provider_and_skips_an_integration_that_is_no_longer_eligible(): void
    {
        $integration = $this->context()['integration'];
        $calls = [];
        $this->app->instance(IntegrationSyncRunner::class, new class($calls) extends IntegrationSyncRunner
        {
            public function __construct(private array &$calls) {}

            public function run(Integration $integration, string $mode): array
            {
                $this->calls[] = [$integration->id, $mode];

                return ['status' => 'ok', 'emitted' => 2, 'unchanged' => 1, 'complete' => true];
            }
        });

        app()->call([new SyncIntegration($integration->id, 'audit'), 'handle']);
        $integration->forceFill(['status' => Integration::STATUS_DISABLED])->save();
        app()->call([new SyncIntegration($integration->id, 'delta'), 'handle']);
        app()->call([new SyncIntegration('01a13078-0000-7000-8000-000000000000', 'delta'), 'handle']);

        $this->assertSame([[$integration->id, 'audit']], $calls);

        $integration->forceFill(['status' => Integration::STATUS_ACTIVE])->save();
        Cache::flush();
        $this->artisan('stripe:sync --inline')->expectsOutput("{$integration->id} ok emitted=2 unchanged=1 complete=yes")->assertSuccessful();
        $this->assertSame([$integration->id, 'delta'], $calls[1]);
    }
}
