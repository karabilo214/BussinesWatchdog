<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentSignal extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'incident_signals';

    protected $primaryKey = 'incident_id';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'incident_id',
        'signal_id',
        'association_reason',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
