<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailChangeRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'new_email', 'confirm_token_hash', 'cancel_token_hash', 'expires_at', 'confirmed_at', 'cancelled_at'];

    protected $hidden = ['confirm_token_hash', 'cancel_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function open(): bool
    {
        return $this->confirmed_at === null && $this->cancelled_at === null && $this->expires_at->isFuture();
    }
}
