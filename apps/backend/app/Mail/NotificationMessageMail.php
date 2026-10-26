<?php

namespace App\Mail;

use App\Support\Mail\MailBody;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NotificationMessageMail extends Mailable
{
    public const LOCALES = ['ru', 'en', 'de'];

    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_NOTIFICATIONS = 'notifications';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_VISITOR = 'visitor';

    public const AUDIENCES = [self::AUDIENCE_CUSTOMER, self::AUDIENCE_NOTIFICATIONS, self::AUDIENCE_STAFF, self::AUDIENCE_VISITOR];

    public readonly string $audience;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        string $locale = 'en',
        string $audience = self::AUDIENCE_CUSTOMER,
        public readonly ?string $greeting = null,
        public readonly ?string $actionLabel = null,
    ) {
        $this->locale(in_array($locale, self::LOCALES, true) ? $locale : 'en');
        $this->audience = in_array($audience, self::AUDIENCES, true) ? $audience : self::AUDIENCE_CUSTOMER;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.layout', text: 'mail.layout-text', with: $this->layout());
    }

    /**
     * @return array<string, mixed>
     */
    public function layout(): array
    {
        $locale = (string) $this->locale;
        $base = rtrim((string) config('app.url'), '/');
        $owner = rtrim((string) config('watchdog.site.owner_url'), '/');
        $hosts = array_values(array_unique(array_filter(array_map(
            fn (string $url): string => mb_strtolower((string) parse_url($url, PHP_URL_HOST)),
            [$base, $owner],
        ))));
        $links = [['label' => trans('mail.footer.site', [], $locale), 'url' => "{$base}/{$locale}"]];

        if ($this->audience === self::AUDIENCE_STAFF) {
            $links[] = ['label' => trans('mail.footer.owner_panel', [], $locale), 'url' => "{$owner}/"];
        } elseif ($this->audience !== self::AUDIENCE_VISITOR) {
            $links[] = ['label' => trans('mail.footer.cabinet', [], $locale), 'url' => "{$base}/app/"];
        }

        if ($this->audience !== self::AUDIENCE_STAFF) {
            $links[] = ['label' => trans('mail.footer.support', [], $locale), 'url' => "{$base}/{$locale}/contact"];
        }

        $provider = array_values(array_filter([
            trim((string) config('watchdog.provider.name')),
            trim(preg_replace('/\s*\R\s*/u', ', ', (string) config('watchdog.provider.address')) ?? ''),
        ], fn (string $part): bool => $part !== ''));

        return [
            'mailLocale' => $locale,
            'mailSubject' => $this->subjectLine,
            'greeting' => $this->greeting,
            'blocks' => MailBody::blocks(
                $this->bodyText,
                $this->actionLabel ?? trans('mail.action', [], $locale),
                trans('mail.link', [], $locale),
                $hosts,
            ),
            'body' => trim($this->bodyText),
            'logoUrl' => "{$base}/mail-logo.png",
            'siteUrl' => "{$base}/{$locale}",
            'reason' => trans("mail.reason.{$this->audience}", [], $locale),
            'settings' => $this->audience === self::AUDIENCE_NOTIFICATIONS
                ? ['label' => trans('mail.footer.notification_settings', [], $locale), 'url' => "{$base}/app/notifications"]
                : null,
            'links' => $links,
            'provider' => implode(' · ', $provider),
        ];
    }
}
