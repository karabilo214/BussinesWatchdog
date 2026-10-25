<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Dto\Auth\AuthSessionDto;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use App\Support\Account\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\Exception\WebauthnException;

class PasskeyController extends Controller
{
    private const CEREMONY_TTL_SECONDS = 300;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $user->passkeys()->orderByDesc('created_at')->get()
            ->map(fn (Passkey $passkey): array => $this->summary($passkey))->all()]);
    }

    public function authorizeRegistration(Request $request, StepUp $stepUp): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);
        $request->session()->put('passkey.registration_authorized', [
            'user_id' => $user->id,
            'expires_at' => Carbon::now()->getTimestamp() + self::CEREMONY_TTL_SECONDS,
        ]);

        return response()->json(['authorized' => true]);
    }

    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->registrationAllowed($request, $user)) {
            return response()->json(['code' => 'passkey_step_up_required'], 403);
        }

        $options = $generate($user);
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));
        $request->session()->put('passkey.registration_issued_at', Carbon::now()->getTimestamp());

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function register(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->registrationAllowed($request, $user) || ! $this->fresh($request->session()->pull('passkey.registration_issued_at'))) {
            return response()->json(['code' => 'passkey_registration_expired'], 403);
        }

        try {
            $passkey = $store($user, trim($request->string('name')->toString()), $request->credential(), $request->registrationOptions());
        } catch (WebauthnException|InvalidPasskeyException) {
            return response()->json(['code' => 'passkey_invalid'], 422);
        } finally {
            $request->session()->forget('passkey.registration_authorized');
        }

        $this->audit($user, 'user.passkey_registered', ['passkey_id' => $passkey->id]);

        return response()->json($this->summary($passkey), 201);
    }

    public function destroy(Request $request, StepUp $stepUp, Passkey $passkey): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        /** @var User $user */
        $user = $request->user();
        if ((string) $passkey->user_id !== $user->id) {
            return response()->json(['code' => 'passkey_not_found'], 404);
        }

        $stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);
        $passkey->delete();
        $this->audit($user, 'user.passkey_deleted', ['passkey_id' => $passkey->id]);

        return response()->json(null, 204);
    }

    public function loginOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));
        $request->session()->put('passkey.verification_issued_at', Carbon::now()->getTimestamp());

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(PasskeyVerificationRequest $request, VerifyPasskey $verify, AuthSessionDto $dto): JsonResponse
    {
        if (! $this->fresh($request->session()->pull('passkey.verification_issued_at'))) {
            $request->session()->forget('passkey.verification_options');

            return response()->json(['code' => 'passkey_challenge_expired'], 401);
        }

        try {
            $passkey = $verify($request->credential(), $request->verificationOptions());
        } catch (WebauthnException|InvalidPasskeyException) {
            return response()->json(['code' => 'passkey_invalid'], 422);
        }

        /** @var User $user */
        $user = $passkey->user;
        if ($user->disabled_at !== null || ! $user->memberships()->exists()) {
            return response()->json(['code' => 'passkey_invalid'], 422);
        }

        Auth::guard('web')->login($user, $request->remember());
        $request->session()->regenerate();
        $activeTenantId = $user->memberships()->value('tenant_id');
        if ($activeTenantId !== null) {
            $request->session()->put('active_tenant_id', $activeTenantId);
        }

        $this->audit($user, 'user.passkey_login');

        return response()->json($dto->toArray($user, $activeTenantId));
    }

    private function registrationAllowed(Request $request, User $user): bool
    {
        $authorized = $request->session()->get('passkey.registration_authorized');

        return is_array($authorized)
            && ($authorized['user_id'] ?? null) === $user->id
            && $this->fresh($authorized['expires_at'] ?? null, expiry: true);
    }

    private function fresh(mixed $time, bool $expiry = false): bool
    {
        $now = Carbon::now()->getTimestamp();

        return is_int($time) && ($expiry ? $time >= $now : $time <= $now && $time >= $now - self::CEREMONY_TTL_SECONDS);
    }

    /** @return array{id: int, name: string, created_at: ?string, last_used_at: ?string} */
    private function summary(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'created_at' => $passkey->created_at?->toJSON(),
            'last_used_at' => $passkey->last_used_at?->toJSON(),
        ];
    }

    /** @param array<string, mixed> $changes */
    private function audit(User $user, string $action, array $changes = []): void
    {
        $requestId = (string) Str::uuid();
        foreach (Membership::query()->where('user_id', $user->id)->pluck('tenant_id') as $tenantId) {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'actor_user_id' => $user->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'changes' => $changes,
                'request_id' => $requestId,
                'created_at' => Carbon::now(),
            ]);
        }
    }
}
