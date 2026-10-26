<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderDispute extends Model
{
    use HasUuids;

    public const STATUSES = ['needs_response', 'under_review', 'won', 'lost', 'warning_needs_response', 'warning_under_review', 'warning_closed', 'prevented'];

    public const OPEN_STATUSES = ['needs_response', 'under_review', 'warning_needs_response', 'warning_under_review'];

    public const AWAITING_RESPONSE_STATUSES = ['needs_response', 'warning_needs_response'];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'payment_id',
        'external_id',
        'payment_external_id',
        'charge_ref',
        'mode',
        'currency',
        'currency_exponent',
        'amount_minor',
        'status',
        'reason',
        'evidence_due_by',
        'opened_at',
        'closed_at',
        'reminded_at',
        'incident_fingerprint',
        'source_revision',
        'source_event_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'amount_minor' => 'string',
            'source_revision' => 'integer',
            'evidence_due_by' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reminded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
