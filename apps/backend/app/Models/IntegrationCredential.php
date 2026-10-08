<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationCredential extends Model
{
    use HasUuids;

    public const KIND_PLUGIN_HMAC = 'plugin_hmac';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAINING = 'draining';

    public const STATUS_REVOKED = 'revoked';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'kind',
        'key_id',
        'ciphertext',
        'key_version',
        'fingerprint',
        'status',
        'expires_at',
        'rotated_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
