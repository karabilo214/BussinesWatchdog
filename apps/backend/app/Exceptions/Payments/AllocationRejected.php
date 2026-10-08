<?php

namespace App\Exceptions\Payments;

use RuntimeException;

class AllocationRejected extends RuntimeException
{
    private const CONFLICT_REASON_CODES = [
        'allocation_already_revoked',
        'allocation_amount_exceeds_capture',
        'allocation_amount_exceeds_refund',
        'allocation_has_active_refund_allocations',
    ];

    public function __construct(
        public readonly string $reasonCode,
    ) {
        parent::__construct($reasonCode);
    }

    public function httpStatus(): int
    {
        return in_array($this->reasonCode, self::CONFLICT_REASON_CODES, true) ? 409 : 422;
    }
}
