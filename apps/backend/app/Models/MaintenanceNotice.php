<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MaintenanceNotice extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const MAX_HOURS = 24;

    public const MAX_DAYS_AHEAD = 90;

    protected $fillable = ['version', 'starts_at', 'ends_at', 'messages', 'created_by', 'reason', 'cancelled_at', 'cancelled_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'messages' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }
}
