<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_id',
        'event_id',
        'source_revision',
        'snapshot',
        'payload_hash',
        'observed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'snapshot' => 'array',
            'observed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventInbox::class, 'event_id');
    }
}
