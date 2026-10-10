<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Dto\Auth\AuthSessionDto;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\EmailVerificationService;
use App\Support\Account\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthSessionDto $authSessionDto,
    ) {}

    public function register(RegisterRequest $request, TeamService $team, EmailVerificationService $emailVerification): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['invitation_token'])) {
            return $this->registerByInvitation($request, $validated, $team);
        }

        $result = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'locale' => $validated['locale'],
            ]);

            $tenant = Tenant::query()->create([
                'name' => $validated['organization_name'],
                'timezone' => $validated['timezone'],
                'locale' => $validated['locale'],
            ]);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            return [$user->fresh(), $tenant];
        });

        [$user, $tenant] = $result;

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $tenant->id);
        $emailVerification->send($user);

        return response()->json($this->authSessionDto->toArray($user, $tenant->id), 201);
    }

    /**
     * Joins the inviting team instead of creating a tenant; the invited address is the only accepted one (ADR 0019).
     *
     * @param  array<string, mixed>  $validated
     */
    private function registerByInvitation(RegisterRequest $request, array $validated, TeamService $team): JsonResponse
    {
        try {
            $invitation = $team->findPending($validated['invitation_token']);

            if ($invitation->email !== $validated['email']) {
                throw new AccountRejected('invitation_email_mismatch');
            }

            [$user, $membership] = DB::transaction(function () use ($validated, $team): array {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password_hash' => Hash::make($validated['password']),
                    'locale' => $validated['locale'],
                ]);

                return [$user, $team->accept($user, $validated['invitation_token'])];
            });
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $membership->tenant_id);

        return response()->json($this->authSessionDto->toArray($user->refresh(), $membership->tenant_id), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! Auth::guard('web')->attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], (bool) ($validated['remember'] ?? false))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $activeTenantId = $user->memberships()->value('tenant_id');

        if ($activeTenantId !== null) {
            $request->session()->put('active_tenant_id', $activeTenantId);
        }

        return response()->json($this->authSessionDto->toArray($user, $activeTenantId));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->authSessionDto->toArray(
            $user,
            $request->session()->get('active_tenant_id'),
        ));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}
