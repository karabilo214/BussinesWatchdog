<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CheckScenario extends Model
{
    use HasUuids;

    public const MODE_PAYMENT_FORM = 'payment_form';

    public const ACCESS_STOP_BEFORE_SIGN_IN = 'stop_before_sign_in';

    public const ACCESS_TEST_CUSTOMER = 'test_customer';

    public const ACCESS_GUEST = 'guest';

    public const ACCESSES = [self::ACCESS_STOP_BEFORE_SIGN_IN, self::ACCESS_TEST_CUSTOMER, self::ACCESS_GUEST];

    public $timestamps = false;

    protected $hidden = ['sign_in_ciphertext', 'sign_in_key_version'];

    protected $fillable = [
        'tenant_id',
        'store_id',
        'name',
        'mode',
        'version',
        'enabled',
        'adapter_version',
        'definition',
        'product_external_id',
        'checkout_access',
        'sign_in_ciphertext',
        'sign_in_key_version',
        'interval_seconds',
        'next_due_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'enabled' => 'boolean',
            'definition' => 'array',
            'interval_seconds' => 'integer',
            'sign_in_key_version' => 'integer',
            'next_due_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
