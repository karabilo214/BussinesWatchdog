<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $tenant->id);

        return response()->json([
            'user' => $this->userDto($user),
            'active_tenant_id' => $tenant->id,
            'memberships' => $this->membershipsDto($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! Auth::attempt([
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

        return response()->json([
            'user' => $this->userDto($user),
            'active_tenant_id' => $activeTenantId,
            'memberships' => $this->membershipsDto($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userDto($user),
            'active_tenant_id' => $request->session()->get('active_tenant_id'),
            'memberships' => $this->membershipsDto($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /**
     * @return array{id: string, name: string, email: string, locale: string, email_verified: bool, mfa_enabled: bool}
     */
    private function userDto(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'email_verified' => $user->email_verified_at !== null,
            'mfa_enabled' => $user->mfa_secret_ciphertext !== null,
        ];
    }

    /**
     * @return array<int, array{tenant_id: string, role: string, created_at: string|null}>
     */
    private function membershipsDto(User $user): array
    {
        return $user->memberships()
            ->orderBy('created_at')
            ->get(['tenant_id', 'role', 'created_at'])
            ->map(fn ($membership): array => [
                'tenant_id' => $membership->tenant_id,
                'role' => $membership->role,
                'created_at' => $membership->created_at?->toJSON(),
            ])
            ->all();
    }
}
