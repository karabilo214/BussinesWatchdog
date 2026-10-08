<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantContext;
use Tests\TestCase;

class TenantContextContainerTest extends TestCase
{
    public function test_tenant_context_is_container_scoped(): void
    {
        $first = $this->app->make(TenantContext::class);
        $second = $this->app->make(TenantContext::class);

        $this->assertSame($first, $second);
    }
}
