<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'salutation', 'first_name', 'last_name', 'company_name', 'email', 'password_hash', 'locale'])]
#[Hidden(['password_hash', 'remember_token', 'mfa_secret_ciphertext'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable;

    public const SALUTATIONS = ['mr', 'ms', 'diverse'];

    /**
     * `name` is the display name, always "first last"; writers that still pass only `name` get it as the last name,
     * never split into parts.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (trim((string) $user->last_name) === '' && trim((string) $user->name) !== '') {
                $user->last_name = trim((string) $user->name);
            }

            $user->name = self::displayName($user->first_name, (string) $user->last_name);
        });
    }

    public static function displayName(?string $firstName, string $lastName): string
    {
        return trim(trim((string) $firstName).' '.trim($lastName));
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'memberships')
            ->withPivot('role', 'created_at');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }
}
