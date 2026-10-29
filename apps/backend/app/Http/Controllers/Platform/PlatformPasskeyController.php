<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Account\StepUp;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformSession;
use App\Support\Platform\StorePlatformPasskey;
use App\Support\Platform\VerifyPlatformPasskey;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class PlatformPasskeyController extends Controller
{
    public const PREFIX = 'platform_passkey.';

    private const TTL = 300;

    public function __construct(private readonly PlatformAudit $audit, private readonly MfaService $mfa) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->staff($request)->passkeys()->orderByDesc('created_at')->get()
            ->map(fn (Passkey $key): array => $this->summary($key))->all()]);
    }

    public function authorizeRegistration(Request $request, StepUp $stepUp): JsonResponse
    {
        $request->session()->forget([self::PREFIX.'registration', self::PREFIX.'authorization']);
        $staff = $this->staff($request);
        $this->stepUp($request, $staff, $stepUp);
        $request->session()->put(self::PREFIX.'authorization', [
            'staff_id' => $staff->id,
            'version' => $staff->permission_version,
            'issued_at' => now()->getTimestamp(),
        ]);

        return response()->json(['authorized' => true]);
    }

    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $staff = $this->staff($request);
        abort_unless($this->authorized($request->session()->get(self::PREFIX.'authorization'), $staff), 403, 'Passkey step-up required.');
        $options = $generate($staff);
        $request->session()->put(self::PREFIX.'registration', ['options' => WebAuthn::toJson($options), 'issued_at' => now()->getTimestamp()]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function register(Request $request, StorePlatformPasskey $store): JsonResponse
    {
        $ceremony = $request->session()->pull(self::PREFIX.'registration');
        $authorization = $request->session()->pull(self::PREFIX.'authorization');
        if (! $this->fresh($ceremony['issued_at'] ?? null) || ! $this->authorized($authorization, $this->staff($request))) {
            return response()->json(['code' => 'passkey_registration_expired'], 403);
        }

        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $credential = $this->credential($request);
        try {
            $passkey = DB::transaction(function () use ($request, $store, $ceremony, $authorization, $validated, $credential): Passkey {
                $staff = PlatformStaff::query()->with('user')->whereKey($this->staff($request)->id)->lockForUpdate()->firstOrFail();
                if (! $this->eligible($staff) || ! $this->authorized($authorization, $staff)) {
                    throw InvalidPasskeyException::make('Staff authorization expired.');
                }

                return $store($staff, trim($validated['name']), $credential, WebAuthn::fromJson($ceremony['options'], PublicKeyCredentialCreationOptions::class));
            });
        } catch (WebauthnException|InvalidPasskeyException|\InvalidArgumentException|\UnexpectedValueException) {
            return response()->json(['code' => 'passkey_invalid'], 422);
        }
        $staff = $this->staff($request);
        $this->audit->record($staff->id, 'staff.passkey_registered', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED, ['passkey_id' => $passkey->id]);

        return response()->json($this->summary($passkey), 201);
    }

    public function destroy(Request $request, StepUp $stepUp, int $platformPasskey): JsonResponse
    {
        $staff = $this->staff($request);
        $key = $staff->passkeys()->findOrFail($platformPasskey);
        $this->stepUp($request, $staff, $stepUp);
        $key->delete();
        $this->audit->record($staff->id, 'staff.passkey_deleted', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED, ['passkey_id' => $key->id]);

        return response()->json(null, 204);
    }

    public function loginOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $request->session()->put(self::PREFIX.'verification', ['options' => WebAuthn::toJson($options), 'issued_at' => now()->getTimestamp()]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(Request $request, VerifyPlatformPasskey $verify, PlatformSession $session, PlatformAuthController $auth): JsonResponse
    {
        $ceremony = $request->session()->pull(self::PREFIX.'verification');
        if (! $this->fresh($ceremony['issued_at'] ?? null)) {
            return response()->json(['code' => 'passkey_challenge_expired'], 401);
        }

        $credential = $this->credential($request);
        try {
            $staff = DB::transaction(function () use ($verify, $credential, $ceremony): PlatformStaff {
                $key = $verify->getPasskey($credential);
                // Same lock order as staff disable/reset: staff first, then credential.
                $staff = PlatformStaff::query()->with('user')->whereKey($key->staff_id)->lockForUpdate()->first();
                if ($staff === null || ! $this->eligible($staff)) {
                    throw InvalidPasskeyException::make('Staff login unavailable.');
                }
                $verify($credential, WebAuthn::fromJson($ceremony['options'], PublicKeyCredentialRequestOptions::class), $staff);

                return $staff;
            });
        } catch (WebauthnException|InvalidPasskeyException|\InvalidArgumentException|\UnexpectedValueException) {
            $this->audit->record(null, 'staff.passkey_login', 'staff_login', 'passkey', PlatformAudit::RESULT_DENIED);

            return response()->json(['code' => 'passkey_invalid'], 422);
        }

        $request->session()->forget([PlatformAuthController::PENDING, self::PREFIX.'registration', self::PREFIX.'authorization']);
        $session->start($request, $staff);
        $this->audit->record($staff->id, 'staff.passkey_login', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED);

        return response()->json($auth->me($staff));
    }

    private function staff(Request $request): PlatformStaff
    {
        return $request->attributes->get('platform_staff');
    }

    private function eligible(PlatformStaff $staff): bool
    {
        return $staff->active() && $staff->user !== null && $staff->user->disabled_at === null && $this->mfa->enabled($staff->user);
    }

    private function stepUp(Request $request, PlatformStaff $staff, StepUp $stepUp): void
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:128'], 'code' => ['required', 'string', 'max:32']]);
        try {
            $stepUp->require($staff->user, $data['current_password'], $data['code']);
        } catch (ValidationException $exception) {
            throw new HttpResponseException(response()->json(['code' => 'passkey_step_up_failed', 'errors' => $exception->errors()], 422));
        }
    }

    private function authorized(mixed $authorization, PlatformStaff $staff): bool
    {
        return is_array($authorization) && ($authorization['staff_id'] ?? null) === $staff->id
            && ($authorization['version'] ?? null) === $staff->permission_version && $this->fresh($authorization['issued_at'] ?? null);
    }

    private function fresh(mixed $time): bool
    {
        return is_int($time) && $time <= now()->getTimestamp() && $time >= now()->getTimestamp() - self::TTL;
    }

    private function credential(Request $request): PublicKeyCredential
    {
        $request->validate(['credential' => ['required', 'array']]);
        try {
            return WebAuthn::fromJson(json_encode($request->input('credential'), JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw ValidationException::withMessages(['credential' => 'Invalid credential format.']);
        }
    }

    private function summary(Passkey $key): array
    {
        return ['id' => $key->id, 'name' => $key->name, 'created_at' => $key->created_at?->toJSON(), 'last_used_at' => $key->last_used_at?->toJSON()];
    }
}
