<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\PasswordResetService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformSession;
use App\Support\Platform\StaffAdmin;
use CBOR\Encoder;
use CBOR\MapObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AuthenticatorData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\U2FPublicKey;

class PlatformPasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = '/platform-api/v1/me/passkeys';

    private const LOGIN = '/platform-api/v1/auth/passkeys/login';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config(['watchdog.platform.login_per_minute' => 100]);
    }

    private function staff(string $email = 'staff@example.test'): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password_hash' => Hash::make('strong-password-1234'), 'locale' => 'ru']);
        $this->secret = app(MfaService::class)->beginSetup($user)['secret'];
        app(MfaService::class)->confirm($user, $this->code());
        $this->travel(31)->seconds();

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE])->refresh();
    }

    private function code(): string
    {
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(now()->getTimestamp()));
    }

    private function staffSession(PlatformStaff $staff): void
    {
        $this->actingAs($staff->user, 'platform')->withSession([
            PlatformSession::LOGIN_AT => now()->getTimestamp(), PlatformSession::LAST_SEEN_AT => now()->getTimestamp(),
            PlatformSession::PERMISSION_VERSION => $staff->permission_version,
        ]);
    }

    private function authorizeKey(PlatformStaff $staff): PublicKeyCredentialCreationOptions
    {
        $this->staffSession($staff);
        $this->postJson(self::PROFILE.'/authorize', ['current_password' => 'strong-password-1234', 'code' => $this->code()])->assertOk();
        $this->getJson(self::PROFILE.'/register/options')->assertOk()
            ->assertJsonPath('options.authenticatorSelection.userVerification', 'required')
            ->assertJsonPath('options.authenticatorSelection.residentKey', 'required');

        return WebAuthn::fromJson(session('platform_passkey.registration')['options'], PublicKeyCredentialCreationOptions::class);
    }

    private function material(): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($private)['ec'];
        $public = U2FPublicKey::convertToCoseKey("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));

        return ['id' => random_bytes(16), 'private' => $private, 'public' => $public];
    }

    private function key(PlatformStaff $staff, bool $customer = false): array
    {
        $material = $this->material();
        $subject = $customer ? $staff->user : $staff;
        $source = CredentialRecord::create(publicKeyCredentialId: $material['id'], type: 'public-key', transports: [], attestationType: 'none', trustPath: EmptyTrustPath::create(), aaguid: Uuid::v4(), credentialPublicKey: $material['public'], userHandle: $subject->getPasskeyUserHandle(), counter: 0);
        $key = $subject->passkeys()->create(['name' => 'Laptop', 'credential_id' => Base64UrlSafe::encodeUnpadded($material['id']), 'credential' => json_decode(WebAuthn::toJson($source), true)]);

        return $material + ['key' => $key, 'subject' => $subject];
    }

    private function assertion(array $material, ?string $origin = null, bool $verified = true, string $path = self::LOGIN, ?string $handle = null, int $counter = 1, ?string $rpId = null): array
    {
        $this->getJson($path.'/options')->assertOk()->assertJsonPath('options.userVerification', 'required');
        $namespace = $path === self::LOGIN ? 'platform_passkey.verification' : 'passkey.verification_options';
        $serialized = session($namespace);
        $options = WebAuthn::fromJson(is_array($serialized) ? $serialized['options'] : $serialized, PublicKeyCredentialRequestOptions::class);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge), 'origin' => $origin ?? config('app.url')]);
        $authData = hash('sha256', $rpId ?? parse_url(config('app.url'), PHP_URL_HOST), true).chr(AuthenticatorData::FLAG_UP | ($verified ? AuthenticatorData::FLAG_UV : 0)).pack('N', $counter);
        openssl_sign($authData.hash('sha256', $client, true), $signature, $material['private'], OPENSSL_ALGO_SHA256);

        return ['credential' => ['id' => Base64UrlSafe::encodeUnpadded($material['id']), 'rawId' => Base64UrlSafe::encodeUnpadded($material['id']), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($client), 'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
            'signature' => Base64UrlSafe::encodeUnpadded($signature), 'userHandle' => Base64UrlSafe::encodeUnpadded($handle ?? $material['subject']->getPasskeyUserHandle()),
        ]]];
    }

    public function test_registration_requires_own_platform_session_password_and_mfa(): void
    {
        $staff = $this->staff();
        $this->getJson(self::PROFILE)->assertUnauthorized();
        $this->actingAs($staff->user, 'web')->getJson(self::PROFILE)->assertUnauthorized();
        $this->staffSession($staff);
        $this->getJson(self::PROFILE.'/register/options')->assertForbidden();
        $this->postJson(self::PROFILE.'/authorize', ['current_password' => 'strong-password-1234'])->assertUnprocessable();
        $this->postJson(self::PROFILE.'/authorize', ['current_password' => 'wrong', 'code' => $this->code()])->assertUnprocessable();
        $this->postJson(self::PROFILE.'/authorize', ['current_password' => 'strong-password-1234', 'code' => 'invalid'])->assertUnprocessable();
        $options = $this->authorizeKey($staff);
        $this->assertNotSame($staff->user->getPasskeyUserHandle(), $options->user->id);
        $this->assertSame($staff->getPasskeyUserHandle(), $options->user->id);
        $this->assertStringContainsString('admin', $options->user->name);
        $this->travel(301)->seconds();
        $this->getJson(self::PROFILE.'/register/options')->assertForbidden();
    }

    public function test_real_attestation_registers_only_once_and_returns_no_key_material(): void
    {
        $staff = $this->staff();
        $options = $this->authorizeKey($staff);
        $material = $this->material();
        $authData = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true).chr(AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV | AuthenticatorData::FLAG_AT)
            .pack('N', 0).str_repeat("\0", 16).pack('n', strlen($material['id'])).$material['id'].$material['public'];
        $attestation = (new Encoder)->encode(['fmt' => 'none', 'authData' => $authData, 'attStmt' => MapObject::create()]);
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge), 'origin' => config('app.url')]);
        $body = ['name' => 'MacBook', 'credential' => ['id' => Base64UrlSafe::encodeUnpadded($material['id']), 'rawId' => Base64UrlSafe::encodeUnpadded($material['id']), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($client), 'attestationObject' => Base64UrlSafe::encodeUnpadded($attestation),
        ]]];
        $this->postJson(self::PROFILE.'/register', $body)->assertCreated()->assertExactJson(['id' => 1, 'name' => 'MacBook', 'created_at' => now()->startOfSecond()->toJSON(), 'last_used_at' => null]);
        $this->assertDatabaseHas('platform_passkeys', ['staff_id' => $staff->id]);
        $this->assertDatabaseCount('passkeys', 0);
        $this->getJson(self::PROFILE)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.credential')->assertJsonMissingPath('data.0.credential_id');
        $this->postJson(self::PROFILE.'/register', $body)->assertForbidden();
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.passkey_registered', 'actor_staff_id' => $staff->id]);
    }

    public function test_signed_passkey_login_opens_only_the_platform_guard_and_rejects_replay(): void
    {
        $staff = $this->staff();
        $key = $this->key($staff);
        $body = $this->assertion($key);
        $this->postJson(self::LOGIN, $body)->assertOk()->assertJsonPath('staff_id', $staff->id)->assertJsonPath('capabilities.0', 'tenants.read');
        $this->assertAuthenticatedAs($staff->user, 'platform');
        $this->assertGuest('web');
        $this->getJson('/platform-api/v1/me')->assertOk();
        $this->assertNotNull($key['key']->refresh()->last_used_at);
        $this->assertSame(1, $key['key']->credential['counter']);
        $this->postJson(self::LOGIN, $body)->assertUnauthorized();
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.passkey_login', 'result' => 'succeeded']);
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson(self::LOGIN.'/options')->assertOk();
        $this->postJson(self::LOGIN, $body)->assertUnprocessable();
        $this->assertGuest('platform');
        $this->postJson(self::LOGIN, $this->assertion($key, counter: 1))->assertUnprocessable();
        $this->assertSame(1, $key['key']->refresh()->credential['counter']);
    }

    public function test_customer_and_platform_credentials_cannot_be_used_in_the_other_guard(): void
    {
        $staff = $this->staff();
        $customerKey = $this->key($staff, customer: true);
        $this->postJson(self::LOGIN, $this->assertion($customerKey))->assertUnprocessable();
        $platformKey = $this->key($staff);
        $this->postJson('/api/v1/auth/passkeys/login', $this->assertion($platformKey, path: '/api/v1/auth/passkeys/login'))->assertUnprocessable();
        $this->assertGuest('web');
        $this->assertGuest('platform');
    }

    public function test_wrong_origin_missing_user_verification_wrong_handle_and_bad_signature_fail(): void
    {
        $staff = $this->staff();
        $material = $this->key($staff);
        $this->postJson(self::LOGIN, $this->assertion($material, origin: 'https://attacker.example'))->assertUnprocessable();
        $this->postJson(self::LOGIN, $this->assertion($material, verified: false))->assertUnprocessable();
        $this->postJson(self::LOGIN, $this->assertion($material, handle: 'wrong-handle'))->assertUnprocessable();
        $this->postJson(self::LOGIN, $this->assertion($material, rpId: 'attacker.example'))->assertUnprocessable();
        $body = $this->assertion($material);
        $body['credential']['response']['signature'] = Base64UrlSafe::encodeUnpadded(random_bytes(70));
        $this->postJson(self::LOGIN, $body)->assertUnprocessable();
        $this->assertGuest('platform');
        $this->assertNull($material['key']->refresh()->last_used_at);
    }

    public function test_malformed_and_expired_challenges_are_consumed_and_customer_challenge_is_not_accepted(): void
    {
        $staff = $this->staff();
        $material = $this->key($staff);
        $body = $this->assertion($material);
        $this->postJson(self::LOGIN, ['credential' => []])->assertUnprocessable();
        $this->postJson(self::LOGIN, $body)->assertUnauthorized();
        $body = $this->assertion($material);
        $this->travel(301)->seconds();
        $this->postJson(self::LOGIN, $body)->assertUnauthorized();
        $this->assertion($material, path: '/api/v1/auth/passkeys/login');
        $this->postJson(self::LOGIN, $body)->assertUnauthorized();
    }

    public function test_disabled_staff_disabled_user_and_staff_without_mfa_cannot_login(): void
    {
        $staff = $this->staff();
        $material = $this->key($staff);
        $body = $this->assertion($material);
        $staff->forceFill(['state' => PlatformStaff::STATE_DISABLED])->save();
        $this->postJson(self::LOGIN, $body)->assertUnprocessable();
        $staff->forceFill(['state' => PlatformStaff::STATE_ACTIVE])->save();
        $staff->user->forceFill(['disabled_at' => now()])->save();
        $this->postJson(self::LOGIN, $this->assertion($material))->assertUnprocessable();
        $staff->user->forceFill(['disabled_at' => null, 'mfa_confirmed_at' => null])->save();
        $this->postJson(self::LOGIN, $this->assertion($material))->assertUnprocessable();
        $this->assertGuest('platform');
    }

    public function test_key_deletion_is_scoped_requires_mfa_and_prevents_further_login(): void
    {
        $other = $this->staff('other@example.test');
        $otherKey = $this->key($other);
        $staff = $this->staff();
        $material = $this->key($staff);
        $body = $this->assertion($material);
        $this->staffSession($staff);
        $this->getJson(self::PROFILE)->assertJsonCount(1, 'data');
        $this->deleteJson(self::PROFILE.'/'.$otherKey['key']->id, ['current_password' => 'strong-password-1234', 'code' => $this->code()])->assertNotFound();
        $this->deleteJson(self::PROFILE.'/'.$material['key']->id, ['current_password' => 'strong-password-1234', 'code' => 'invalid'])->assertUnprocessable();
        $this->deleteJson(self::PROFILE.'/'.$material['key']->id, ['current_password' => 'strong-password-1234', 'code' => $this->code()])->assertNoContent();
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->postJson(self::LOGIN, $this->assertion($material))->assertUnprocessable();
        $this->assertDatabaseHas('platform_passkeys', ['id' => $otherKey['key']->id]);
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.passkey_deleted', 'actor_staff_id' => $staff->id]);
    }

    public function test_mfa_reset_and_password_recovery_revoke_platform_keys_but_leave_customer_keys(): void
    {
        $staff = $this->staff();
        $material = $this->key($staff);
        $customer = $this->key($staff, customer: true);
        app(MfaService::class)->disable($staff->user);
        $this->assertDatabaseMissing('platform_passkeys', ['id' => $material['key']->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $customer['key']->id]);
        $material = $this->key($staff);
        $token = 'reset-token';
        DB::table('password_reset_tokens')->insert(['email' => $staff->user->email, 'token' => hash('sha256', $token), 'created_at' => now()]);
        app(PasswordResetService::class)->complete($staff->user->email, $token, 'new-password-123456');
        $this->assertDatabaseMissing('platform_passkeys', ['id' => $material['key']->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $customer['key']->id]);
    }

    public function test_passkey_options_are_rate_limited_per_ip_without_blocking_password_fallback(): void
    {
        config(['watchdog.platform.login_per_minute' => 5]);
        foreach (range(1, 5) as $i) {
            $this->getJson(self::LOGIN.'/options')->assertOk();
        }
        $this->getJson(self::LOGIN.'/options')->assertStatus(429);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])->getJson(self::LOGIN.'/options')->assertOk();
    }

    public function test_staff_disable_revokes_keys_and_permission_change_invalidates_enrollment(): void
    {
        $owner = $this->staff('owner@example.test');
        $staff = $this->staff();
        $key = $this->key($staff);
        app(StaffAdmin::class)->update($owner, $staff->id, ['state' => 'disabled'], 'Revocation test');
        $this->assertDatabaseMissing('platform_passkeys', ['id' => $key['key']->id]);
        $staff->refresh()->forceFill(['state' => 'active'])->save();
        $this->authorizeKey($staff);
        $authorization = session('platform_passkey.authorization');
        $staff->increment('permission_version');
        $this->withSession([PlatformSession::PERMISSION_VERSION => $staff->permission_version, 'platform_passkey.authorization' => $authorization]);
        $this->getJson(self::PROFILE.'/register/options')->assertForbidden();
    }
}
