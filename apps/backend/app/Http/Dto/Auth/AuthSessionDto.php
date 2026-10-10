<?php

namespace App\Http\Dto\Auth;

use App\Models\User;

class AuthSessionDto
{
    /**
     * @return array{user: array{id: string, name: string, email: string, locale: string, email_verified: bool, mfa_enabled: bool}, active_tenant_id: string|null, memberships: array<int, array{tenant_id: string, role: string, created_at: string|null}>}
     */
    public function toArray(User $user, ?string $activeTenantId): array
    {
        return [
            'user' => $this->user($user),
            'active_tenant_id' => $activeTenantId,
            'memberships' => $this->memberships($user),
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, locale: string, email_verified: bool, mfa_enabled: bool}
     */
    private function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'email_verified' => $user->email_verified_at !== null,
            'mfa_enabled' => $user->mfa_confirmed_at !== null,
        ];
    }

    /**
     * @return array<int, array{tenant_id: string, role: string, created_at: string|null}>
     */
    private function memberships(User $user): array
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
