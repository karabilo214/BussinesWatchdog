<?php

namespace App\Exceptions\Tenancy;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("Tenant context is required for {$operation}.");
    }
}
