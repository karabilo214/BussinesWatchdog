<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Passkey;

class PlatformPasskey extends Passkey
{
    protected $table = 'platform_passkeys';

    protected $hidden = ['credential', 'credential_id'];

    /** The library's subject is the staff identity, never its linked customer user. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }
}
