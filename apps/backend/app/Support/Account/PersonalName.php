<?php

namespace App\Support\Account;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Salutation, first and last name and company of a customer user (ADR 0019). Only the last name is required; the
 * company is the person's own and is never tied to the organisation name or the Paddle payer.
 */
class PersonalName
{
    public const FIELDS = ['salutation', 'first_name', 'last_name', 'company_name'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial = false): array
    {
        return [
            'salutation' => ['sometimes', 'nullable', 'string', Rule::in(User::SALUTATIONS)],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => [...($partial ? ['sometimes'] : ['required']), 'string', 'min:1', 'max:100'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Older clients send a single `name`; it becomes the last name as typed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        if (! array_key_exists('last_name', $input) && ! array_key_exists('first_name', $input) && array_key_exists('name', $input)) {
            $input['last_name'] = $input['name'];
        }

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]) === '' && $field !== 'last_name' ? null : trim($input[$field]);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    public static function only(array $validated): array
    {
        return array_intersect_key($validated, array_flip(self::FIELDS));
    }

    /** "Sehr geehrte Frau Müller," for Herr/Frau, otherwise "Guten Tag Alex Müller," — the gender is never guessed. */
    public static function greeting(User $user, string $locale): string
    {
        $key = in_array($user->salutation, ['mr', 'ms'], true) ? $user->salutation : 'neutral';

        return trans("account.greeting.{$key}", ['last_name' => $user->last_name, 'name' => $user->name], $locale);
    }
}
