<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'status',
        'locale',
        'timezone',
        'config_version',
        'show_money_amounts_to_team',
    ];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'show_money_amounts_to_team' => 'boolean',
            'trial_consumed_at' => 'datetime',
            'order_volume_paused_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot('role', 'created_at');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
