<?php

namespace App\Support\Account;

use App\Mail\NotificationMessageMail;
use Illuminate\Support\Facades\Mail;

class AccountMailer
{
    public const LOCALES = ['ru', 'en', 'de'];

    /**
     * @param  array<string, string|int>  $replace
     */
    public function send(string $email, string $locale, string $template, array $replace): void
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';

        Mail::to($email)->send(new NotificationMessageMail(
            trans("account.{$template}.subject", $replace, $locale),
            trans("account.{$template}.body", $replace, $locale),
        ));
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
