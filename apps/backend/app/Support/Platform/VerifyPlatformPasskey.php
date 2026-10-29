<?php

namespace App\Support\Platform;

use App\Models\PlatformPasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredential;

class VerifyPlatformPasskey extends VerifyPasskey
{
    public function getPasskey(PublicKeyCredential $credential, bool $lock = false): Passkey
    {
        $query = PlatformPasskey::query()->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId));

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw InvalidPasskeyException::make('Passkey not recognized.');
    }

    public function ensurePasskeyBelongsToUser(Passkey $passkey, ?PasskeyUser $user): void
    {
        if ($user !== null && (string) $passkey->staff_id !== (string) $user->getKey()) {
            throw InvalidPasskeyException::make('Passkey not recognized.');
        }
    }
}
