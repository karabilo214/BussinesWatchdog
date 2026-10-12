<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    use HasUuids;

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_IN_PROGRESS, self::STATUS_CLOSED];

    protected $fillable = ['locale', 'name', 'email', 'company', 'message', 'status', 'note', 'handled_by', 'closed_at', 'notified_at'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
