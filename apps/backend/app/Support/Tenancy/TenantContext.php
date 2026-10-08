<?php

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\MissingTenantContext;
use Closure;

class TenantContext
{
    private ?string $tenantId = null;

    private ?string $source = null;

    public function set(string $tenantId, string $source): void
    {
        $this->tenantId = $tenantId;
        $this->source = $source;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->source = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function requireTenantId(string $operation): string
    {
        if ($this->tenantId === null) {
            throw MissingTenantContext::forOperation($operation);
        }

        return $this->tenantId;
    }

    public function scope(string $tenantId, string $source, Closure $callback): mixed
    {
        $previousTenantId = $this->tenantId;
        $previousSource = $this->source;

        $this->set($tenantId, $source);

        try {
            return $callback($this);
        } finally {
            $this->tenantId = $previousTenantId;
            $this->source = $previousSource;
        }
    }
}
