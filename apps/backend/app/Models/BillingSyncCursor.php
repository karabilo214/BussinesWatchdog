<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingSyncCursor extends Model
{
    use HasUuids;

    public const CREATED_AT = null;

    protected $fillable = ['provider', 'provider_account_ref', 'mode', 'cursor', 'last_success_at', 'last_error_code'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }
}
