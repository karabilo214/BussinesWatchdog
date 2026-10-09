<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttemptWindow extends Model
{
    use HasUuids;

    public const OUTCOME_COUNTERS = ['paid', 'on_hold', 'failed', 'pending_stuck', 'late_success', 'rejected_before_order'];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'event_id',
        'payment_method',
        'window_start',
        'window_end',
        'paid',
        'on_hold',
        'failed',
        'pending_stuck',
        'late_success',
        'rejected_before_order',
        'trailing_failures',
        'failure_classes',
        'source_revision',
        'payload_hash',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'paid' => 'integer',
            'on_hold' => 'integer',
            'failed' => 'integer',
            'pending_stuck' => 'integer',
            'late_success' => 'integer',
            'rejected_before_order' => 'integer',
            'trailing_failures' => 'integer',
            'failure_classes' => 'array',
            'source_revision' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function successes(): int
    {
        return $this->paid + $this->on_hold + $this->late_success;
    }

    public function failures(): int
    {
        return $this->failed + $this->pending_stuck;
    }
}
