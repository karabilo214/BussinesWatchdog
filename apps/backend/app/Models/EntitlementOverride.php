<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EntitlementOverride extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'entitlements', 'reason', 'created_by', 'approved_by', 'starts_at', 'ends_at', 'revoked_at', 'created_at'];

    protected function casts(): array
    {
        return ['entitlements' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
