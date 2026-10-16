<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicStorefrontCheck extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const VERDICT_CHECKOUT_REACHED = 'checkout_reached';

    public const VERDICT_NEEDS_CONNECTION = 'needs_connection';

    public const VERDICT_PUBLIC_ISSUE = 'public_issue';

    public const VERDICT_BLOCKED = 'blocked';

    public const VERDICT_INSUFFICIENT_DATA = 'insufficient_data';

    public $timestamps = true;

    protected $fillable = [
        'result_token_hash',
        'locale',
        'submitted_url',
        'normalized_origin',
        'normalized_host',
        'ip_hash',
        'request_ip',
        'request_user_agent',
        'purged_at',
        'status',
        'verdict',
        'error_code',
        'steps',
        'diagnostics',
        'fencing_token',
        'lease_token_hash',
        'lease_until',
        'absolute_deadline_at',
        'browser_version',
        'location',
        'result_hash',
        'queued_at',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    protected $hidden = ['result_token_hash', 'ip_hash', 'request_ip', 'request_user_agent', 'lease_token_hash'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'diagnostics' => 'array',
            'fencing_token' => 'integer',
            'lease_until' => 'datetime',
            'absolute_deadline_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(PublicStorefrontCheckArtifact::class, 'check_id');
    }
}
