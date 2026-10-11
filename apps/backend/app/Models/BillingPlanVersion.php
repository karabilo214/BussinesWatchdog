<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPlanVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['plan_id', 'version', 'state', 'localized_content', 'entitlements', 'trial_days', 'created_by', 'published_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'localized_content' => 'array',
            'entitlements' => 'array',
            'trial_days' => 'integer',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'plan_id');
    }
}
