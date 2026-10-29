<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckRun extends Model
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

    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    /** Started by platform staff on behalf of the customer (ADR 0048): never counted against the customer's usage. */
    public const TRIGGER_SUPPORT = 'support';

    public const BROWSER_CHROMIUM = 'chromium';

    public const BROWSER_FIREFOX = 'firefox';

    public const BROWSER_WEBKIT = 'webkit';

    public const BROWSERS = [self::BROWSER_CHROMIUM, self::BROWSER_FIREFOX, self::BROWSER_WEBKIT];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'scenario_id',
        'scenario_version',
        'trigger',
        'dedupe_key',
        'status',
        'config_snapshot',
        'scheduled_at',
        'started_at',
        'finished_at',
        'last_fencing_token',
        'next_attempt_at',
        'error_code',
        'browser',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'scenario_version' => 'integer',
            'config_snapshot' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_fencing_token' => 'integer',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CheckAttempt::class, 'run_id')->orderBy('attempt_number');
    }
}
