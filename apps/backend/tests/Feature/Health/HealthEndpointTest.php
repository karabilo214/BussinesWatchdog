<?php

namespace Tests\Feature\Health;

use App\Support\Health\ReadinessChecks;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_live_endpoint_returns_ok(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'backend');
    }

    public function test_ready_endpoint_returns_ok_when_all_checks_pass(): void
    {
        $this->app->instance(ReadinessChecks::class, new class extends ReadinessChecks
        {
            public function all(): array
            {
                return [
                    'database' => ['ok' => true],
                    'redis' => ['ok' => true],
                    'cache' => ['ok' => true],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.redis.ok', true)
            ->assertJsonPath('checks.cache.ok', true);
    }

    public function test_ready_endpoint_returns_unavailable_when_a_check_fails(): void
    {
        $this->app->instance(ReadinessChecks::class, new class extends ReadinessChecks
        {
            public function all(): array
            {
                return [
                    'database' => ['ok' => true],
                    'redis' => ['ok' => false],
                    'cache' => ['ok' => true],
                ];
            }
        });

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.redis.ok', false);
    }
}
