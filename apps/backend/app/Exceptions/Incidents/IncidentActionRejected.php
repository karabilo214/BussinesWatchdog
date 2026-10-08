<?php

namespace App\Exceptions\Incidents;

use RuntimeException;

class IncidentActionRejected extends RuntimeException
{
    private const CONFLICT_REASON_CODES = [
        'incident_already_resolved',
        'incident_resolved_cannot_acknowledge',
        'suppression_already_revoked',
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
