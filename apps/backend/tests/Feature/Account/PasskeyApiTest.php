<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use CBOR\Encoder;
use CBOR\MapObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AuthenticatorData;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\U2FPublicKey;

class PasskeyApiTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/auth/passkeys';

    public function test_registration_options_require_fresh_password_step_up_and_are_private(): void
    {
        $user = $this->user();
        $this->getJson(self::PATH)->assertUnauthorized();
        $this->getJson(self::PATH.'/register/options')->assertUnauthorized();
        $this->actingAs($user)->getJson(self::PATH.'/register/options')->assertForbidden();
        $this->postJson(self::PATH.'/authorize', ['current_password' => 'wrong'])->assertStatus(422);
        $this->postJson(self::PATH.'/authorize', ['current_password' => 'very-secure-password'])->assertOk();
        $options = $this->getJson(self::PATH.'/register/options')->assertOk();
        $this->assertSame('required', $options->json('options.authenticatorSelection.userVerification'));
        $this->assertSame('required', $options->json('options.authenticatorSelection.residentKey'));
        $this->travel(6)->minutes();
        $this->getJson(self::PATH.'/register/options')->assertForbidden();
    }

    public function test_key_list_and_deletion_are_user_scoped_and_need_step_up(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        $key = $owner->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'fixture-1', 'credential' => []]);
        $otherKey = $other->passkeys()->create(['name' => 'Other', 'credential_id' => 'fixture-2', 'credential' => []]);

        $this->actingAs($owner)->getJson(self::PATH)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['credential_id' => 'fixture-1']);
        $this->deleteJson(self::PATH.'/'.$otherKey->id, ['current_password' => 'very-secure-password'])->assertNotFound();
        $this->deleteJson(self::PATH.'/'.$key->id, ['current_password' => 'wrong'])->assertStatus(422);
        $this->assertDatabaseHas('passkeys', ['id' => $key->id]);
        $this->deleteJson(self::PATH.'/'.$key->id, ['current_password' => 'very-secure-password'])->assertNoContent();
        $this->assertDatabaseMissing('passkeys', ['id' => $key->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $otherKey->id]);
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $owner->id)->where('action', 'user.passkey_deleted')->count());
    }

    public function test_valid_attestation_registers_a_key_only_after_step_up(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson(self::PATH.'/authorize', ['current_password' => 'very-secure-password'])->assertOk();
        $this->getJson(self::PATH.'/register/options')->assertOk();
        $options = WebAuthn::fromJson(session('passkey.registration_options'), PublicKeyCredentialCreationOptions::class);
        $credentialId = random_bytes(16);
        $ecPrivateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($ecPrivateKey);
        $ec = $details['ec'];
        $publicKey = U2FPublicKey::convertToCoseKey("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        $rpHash = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true);
        $authData = $rpHash.chr(AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV | AuthenticatorData::FLAG_AT)
            .pack('N', 0).str_repeat("\0", 16).pack('n', strlen($credentialId)).$credentialId.$publicKey;
        $attestation = (new Encoder)->encode(['fmt' => 'none', 'authData' => $authData, 'attStmt' => MapObject::create()]);
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge), 'origin' => config('app.url')]);
        $body = ['name' => 'Laptop', 'credential' => [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => ['clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData), 'attestationObject' => Base64UrlSafe::encodeUnpadded($attestation)],
        ]];

        $this->postJson(self::PATH.'/register', $body)->assertCreated()->assertJsonPath('name', 'Laptop');
        $this->assertDatabaseHas('passkeys', ['user_id' => $user->id, 'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId)]);
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'user.passkey_registered')->count());
        $this->postJson(self::PATH.'/register', $body)->assertForbidden();
    }

    public function test_signed_assertion_authenticates_once_and_rejects_replay(): void
    {
        $user = $this->user();
        $credentialId = random_bytes(16);
        $ecPrivateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($ecPrivateKey);
        $details = openssl_pkey_get_details($ecPrivateKey);
        $this->assertIsArray($details);
        $ec = $details['ec'];
        $publicKey = U2FPublicKey::convertToCoseKey("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        $source = CredentialRecord::create(
            publicKeyCredentialId: $credentialId,
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: $publicKey,
            userHandle: $user->getPasskeyUserHandle(),
            counter: 0,
        );
        $passkey = $user->passkeys()->create([
            'name' => 'Authenticator',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true),
        ]);

        $this->getJson(self::PATH.'/login/options')->assertOk();
        $options = session('passkey.verification_options');
        $this->assertIsString($options);
        $challenge = WebAuthn::fromJson($options, PublicKeyCredentialRequestOptions::class)->challenge;
        $origin = config('app.url');
        $clientDataRaw = json_encode(['type' => 'webauthn.get', 'challenge' => Base64UrlSafe::encodeUnpadded($challenge), 'origin' => $origin]);
        $clientData = CollectedClientData::create($clientDataRaw, json_decode($clientDataRaw, true));
        $flags = chr(AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV);
        $rpHash = hash('sha256', parse_url($origin, PHP_URL_HOST), true);
        $authDataRaw = $rpHash.$flags.pack('N', 1);
        $authData = AuthenticatorData::create(authData: $authDataRaw, rpIdHash: $rpHash, flags: $flags, signCount: 1);
        openssl_sign($authDataRaw.hash('sha256', $clientDataRaw, true), $signature, $ecPrivateKey, OPENSSL_ALGO_SHA256);
        $body = ['credential' => [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataRaw),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authDataRaw),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()),
            ],
        ]];

        $this->postJson(self::PATH.'/login', $body)->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertNotNull($passkey->refresh()->last_used_at);
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'user.passkey_login')->count());
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson(self::PATH.'/login', $body)->assertStatus(401)->assertJsonPath('code', 'passkey_challenge_expired');
        $this->getJson(self::PATH.'/login/options')->assertOk();
        $this->postJson(self::PATH.'/login', $body)->assertStatus(422)->assertJsonPath('code', 'passkey_invalid');
        $this->assertGuest();
    }

    private function user(string $email = 'owner@example.test'): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }
}
