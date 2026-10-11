<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    use HasUuids;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_RETIRED = 'retired';

    public $timestamps = false;

    protected $fillable = ['code', 'state', 'created_at'];

    public function versions(): HasMany
    {
        return $this->hasMany(BillingPlanVersion::class, 'plan_id');
    }
}
