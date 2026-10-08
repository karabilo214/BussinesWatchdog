<?php

namespace Tests\Unit\Tenancy;

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    public function test_it_fails_closed_without_tenant(): void
    {
        $context = new TenantContext();

        $this->expectException(MissingTenantContext::class);

        $context->requireTenantId('test operation');
    }

    public function test_it_returns_current_tenant(): void
    {
        $context = new TenantContext();

        $context->set('tenant-1', 'test');

        $this->assertTrue($context->hasTenant());
        $this->assertSame('tenant-1', $context->requireTenantId('test operation'));
        $this->assertSame('test', $context->source());
    }

    public function test_scope_restores_previous_context(): void
    {
        $context = new TenantContext();
        $context->set('tenant-a', 'outer');

        $result = $context->scope('tenant-b', 'inner', function (TenantContext $scoped): string {
            $this->assertSame('tenant-b', $scoped->requireTenantId('inner operation'));
            $this->assertSame('inner', $scoped->source());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame('tenant-a', $context->requireTenantId('outer operation'));
        $this->assertSame('outer', $context->source());
    }

    public function test_scope_restores_context_after_exception(): void
    {
        $context = new TenantContext();
        $context->set('tenant-a', 'outer');

        try {
            $context->scope('tenant-b', 'inner', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            //
        }

        $this->assertSame('tenant-a', $context->requireTenantId('outer operation'));
        $this->assertSame('outer', $context->source());
    }
}
