<?php

namespace App\Support\Site;

use RuntimeException;

class PolicyAcceptanceRequired extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $required
     */
    public function __construct(public readonly array $required)
    {
        parent::__construct('policy_acceptance_required');
    }
}
