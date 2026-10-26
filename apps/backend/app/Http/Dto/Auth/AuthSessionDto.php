<?php

namespace App\Http\Dto\Auth;

use App\Models\DataDeletionRequest;
use App\Models\User;

class AuthSessionDto
{
    /**
     * @return array{user: array{id: string, name: string, salutation: string|null, first_name: string|null, last_name: string, company_name: string|null, email: string, locale: string, email_verified: bool, mfa_enabled: bool}, active_tenant_id: string|null, memberships: array<int, array{tenant_id: string, role: string, created_at: string|null}>}
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
     * @return array{id: string, name: string, salutation: string|null, first_name: string|null, last_name: string, company_name: string|null, email: string, locale: string, email_verified: bool, mfa_enabled: bool}
     */
    private function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'salutation' => $user->salutation,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'company_name' => $user->company_name,
            'email' => $user->email,
            'locale' => $user->locale,
            'email_verified' => $user->email_verified_at !== null,
            'mfa_enabled' => $user->mfa_confirmed_at !== null,
        ];
    }

    /**
     * @return array<int, array{tenant_id: string, role: string, created_at: string|null, tenant_status: string|null, deletion_purge_after: string|null}>
     */
    private function memberships(User $user): array
    {
        $memberships = $user->memberships()->with('tenant:id,status')->orderBy('created_at')->get(['tenant_id', 'role', 'created_at']);
        $deletions = DataDeletionRequest::query()
            ->whereIn('tenant_id', $memberships->pluck('tenant_id'))
            ->whereIn('status', DataDeletionRequest::OPEN_STATUSES)
            ->get(['tenant_id', 'purge_after'])
            ->keyBy('tenant_id');

        return $memberships
            ->map(fn ($membership): array => [
                'tenant_id' => $membership->tenant_id,
                'role' => $membership->role,
                'created_at' => $membership->created_at?->toJSON(),
                'tenant_status' => $membership->tenant?->status,
                'deletion_purge_after' => $deletions->get($membership->tenant_id)?->purge_after?->toJSON(),
            ])
            ->values()
            ->all();
    }
}
