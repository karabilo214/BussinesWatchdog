<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasUuids;

    public const MAX_WINDOW_DAYS = 30;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'incident_id',
        'scope',
        'reason',
        'created_by',
        'starts_at',
        'ends_at',
        'revoked_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
