<?php

namespace App\Support\Account;

use App\Mail\NotificationMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AccountMailer
{
    public const LOCALES = ['ru', 'en', 'de'];

    /**
     * @param  array<string, string|int>  $replace
     */
    public function send(string $email, string $locale, string $template, array $replace): void
    {
        Mail::to($email)->send($this->letter($email, $locale, $template, $replace));
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public function letter(string $email, string $locale, string $template, array $replace, ?string $greeting = null): NotificationMessageMail
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $user = $greeting === null ? User::query()->where('email', mb_strtolower(trim($email)))->first() : null;

        return new NotificationMessageMail(
            trans("account.{$template}.subject", $replace, $locale),
            trans("account.{$template}.body", $replace, $locale),
            $locale,
            str_starts_with($template, 'staff_') ? NotificationMessageMail::AUDIENCE_STAFF : NotificationMessageMail::AUDIENCE_CUSTOMER,
            $greeting ?? ($user !== null ? PersonalName::greeting($user, $locale) : trans('account.greeting.anonymous', [], $locale)),
            trans()->has("account.{$template}.action", $locale) ? trans("account.{$template}.action", $replace, $locale) : null,
        );
    }

    public function deliver(string $email, string $subject, string $body, string $locale = 'en', string $audience = NotificationMessageMail::AUDIENCE_CUSTOMER): void
    {
        Mail::to($email)->send(new NotificationMessageMail($subject, $body, $locale, $audience));
    }

    /** Absolute link into the customer app; relative `$path` starts with "/app/" or "/api/". */
    public function link(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    public function role(string $role, string $locale): string
    {
        return trans("account.roles.{$role}", [], in_array($locale, self::LOCALES, true) ? $locale : 'en');
    }
}
