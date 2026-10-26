<?php

namespace App\Support\Account;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Site\Policies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A new customer: user, organisation and owner membership, together with the consent to the current service terms
 * and privacy notice (ADR 0042); nothing is created when the consent does not match the published documents.
 */
class Registration
{
    public function __construct(private readonly Policies $policies) {}

    /**
     * @param  array{salutation?: string|null, first_name?: string|null, last_name: string, company_name?: string|null, email: string, password: string, organization_name: string, timezone: string, locale: string}  $input
     * @param  list<string>  $policyVersionIds
     * @return array{0: User, 1: Tenant}
     */
    public function register(array $input, array $policyVersionIds): array
    {
        return DB::transaction(function () use ($input, $policyVersionIds): array {
            $user = User::query()->create(PersonalName::only($input) + [
                'email' => $input['email'],
                'password_hash' => Hash::make($input['password']),
                'locale' => $input['locale'],
            ]);

            $tenant = Tenant::query()->create([
                'name' => $input['organization_name'],
                'timezone' => $input['timezone'],
                'locale' => $input['locale'],
            ]);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            $this->policies->accept($user->id, $tenant->id, Policies::PURPOSE_SIGNUP, $policyVersionIds, $input['locale']);

            return [$user->fresh(), $tenant];
        });
    }
}
