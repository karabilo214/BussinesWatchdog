<?php

namespace App\Support\Platform;

use App\Models\PlatformPasskey;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\CredentialRecord;

/** Reuse the audited WebAuthn validator without changing Laravel Passkeys' global customer model. */
class StorePlatformPasskey extends StorePasskey
{
    protected function ensureCredentialIsUnique(CredentialRecord $source): void
    {
        if (PlatformPasskey::query()->where('credential_id', Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId))->exists()) {
            throw InvalidPasskeyException::make('Unable to register this passkey.');
        }
    }
}
