<?php

namespace App\Support\Mail;

use App\Mail\NotificationMessageMail;
use App\Models\NotificationDelivery;
use App\Support\Account\AccountMailer;
use App\Support\Notifications\NotificationRenderer;
use App\Support\Notifications\RenderedNotification;

final class MailPreviews
{
    public const ADDRESS = 'preview@example.test';

    private const DELIVERED_TEMPLATES = ['reminder_subscription', 'reminder_payment', 'reminder_other'];

    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly NotificationRenderer $renderer,
    ) {}

    /**
     * @return list<string>
     */
    public static function accountTemplates(): array
    {
        $templates = [];

        foreach (require lang_path('en/account.php') as $key => $value) {
            if (is_array($value) && isset($value['subject'], $value['body'])) {
                $templates[] = (string) $key;
            }
        }

        return $templates;
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, NotificationMessageMail> keyed by "<letter>.<locale>"
     */
    public function all(array $locales = NotificationMessageMail::LOCALES): array
    {
        $letters = [];

        foreach ($locales as $locale) {
            foreach (self::accountTemplates() as $template) {
                $letters["account.{$template}.{$locale}"] = in_array($template, self::DELIVERED_TEMPLATES, true)
                    ? new NotificationMessageMail(
                        trans("account.{$template}.subject", $this->sample("account.{$template}", $locale), $locale),
                        trans("account.{$template}.body", $this->sample("account.{$template}", $locale), $locale),
                        $locale,
                    )
                    : $this->mailer->letter(self::ADDRESS, $locale, $template, $this->sample("account.{$template}", $locale), trans('account.greeting.ms', ['last_name' => 'Müller'], $locale));
            }

            foreach (['problem', 'recovered'] as $kind) {
                $replace = $this->sample("account.service_alert.{$kind}", $locale);
                $letters["service_alert.{$kind}.{$locale}"] = new NotificationMessageMail(
                    trans("account.service_alert.{$kind}.subject", $replace, $locale),
                    trans("account.service_alert.{$kind}.body", $replace, $locale),
                    $locale,
                    NotificationMessageMail::AUDIENCE_STAFF,
                );
            }

            foreach ($this->notifications($locale) as $name => $message) {
                $letters["notification.{$name}.{$locale}"] = new NotificationMessageMail($message->subject, $message->body, $message->locale, NotificationMessageMail::AUDIENCE_NOTIFICATIONS);
            }

            $letters["contact.reply.{$locale}"] = new NotificationMessageMail(
                trans('site.contact.reply.templates.answer.subject', [], $locale),
                trans('site.contact.reply.letter', [
                    'name' => 'Anna Müller',
                    'body' => trim(trans('site.contact.reply.templates.answer.body', [], $locale)),
                    'message' => 'Can I connect a second store to the same subscription?',
                    'date' => '26.10.2026 10:15 CET',
                ], $locale),
                $locale,
                NotificationMessageMail::AUDIENCE_VISITOR,
            );
        }

        $letters['contact.notify.ru'] = new NotificationMessageMail(
            trans('site.contact.mail.subject', ['name' => 'Anna Müller'], 'ru'),
            trans('site.contact.mail.body', [
                'name' => 'Anna Müller',
                'email' => 'anna@example.test',
                'company' => 'Kaffee Rösterei GmbH',
                'locale' => 'de',
                'message' => 'Can I connect a second store to the same subscription?',
                'attachment' => '—',
                'link' => $this->owner('/contact-requests/01900000-0000-7000-8000-000000000001'),
            ], 'ru'),
            'ru',
            NotificationMessageMail::AUDIENCE_STAFF,
        );

        return $letters;
    }

    /**
     * @return array<string, RenderedNotification>
     */
    private function notifications(string $locale): array
    {
        $incident = [
            'kind' => NotificationDelivery::KIND_INCIDENT_OPENED,
            'locale' => $locale,
            'timezone' => 'Europe/Berlin',
            'store_name' => 'Kaffee Rösterei',
            'severity' => 'critical',
            'family' => 'money',
            'component' => 'capture',
            'rule_code' => 'MONEY_CAPTURE_MISSING',
            'order_number' => '1042',
            'currency' => 'EUR',
            'amount_minor' => '8900',
            'currency_exponent' => 2,
            'possible_start_from' => '2026-10-26T08:10:00Z',
            'possible_start_to' => '2026-10-26T08:40:00Z',
            'recovered_at' => null,
            'source' => 'reconciliation',
            'first_step' => 'open_payment_by_reference',
            'first_step_params' => ['provider' => 'stripe', 'reference' => 'pi_3PreviewExample'],
            'checked_steps' => ['store_order_data', 'provider_transactions', 'payment_allocations'],
            'link' => $this->app('/app/incidents/01900000-0000-7000-8000-000000000002'),
        ];

        return [
            'incident_opened' => $this->renderer->render($incident),
            'incident_recovered' => $this->renderer->render(['kind' => NotificationDelivery::KIND_INCIDENT_RECOVERED, 'recovered_at' => '2026-10-26T11:05:00Z'] + $incident),
            'daily_digest' => $this->renderer->render([
                'kind' => NotificationDelivery::KIND_DAILY_DIGEST,
                'locale' => $locale,
                'timezone' => 'Europe/Berlin',
                'period_from' => '2026-10-25T06:00:00Z',
                'period_to' => '2026-10-26T06:00:00Z',
                'verdict' => 'problems',
                'stores' => [[
                    'name' => 'Kaffee Rösterei',
                    'paused' => false,
                    'active' => ['critical' => 1, 'warning' => 0, 'info' => 0],
                    'opened' => 1,
                    'resolved' => 2,
                    'checks' => ['passed' => 23, 'failed' => 1, 'other' => 0],
                    'last_successful_check_at' => '2026-10-26T05:30:00Z',
                    'gaps' => [],
                ]],
                'incidents' => [[
                    'store_name' => 'Kaffee Rösterei',
                    'severity' => 'critical',
                    'family' => 'money',
                    'component' => 'capture',
                    'first_seen_at' => '2026-10-26T08:40:00Z',
                    'link' => $incident['link'],
                ]],
                'more_incidents' => 0,
                'discrepancies' => [['mode' => 'live', 'component' => 'capture', 'currency' => 'EUR', 'currency_exponent' => 2, 'total_minor' => '8900', 'incident_count' => 1]],
                'unknown_amount_incidents' => 0,
                'link' => $this->app('/app'),
            ]),
            'test' => $this->renderer->render(['kind' => NotificationDelivery::KIND_TEST, 'locale' => $locale, 'timezone' => 'Europe/Berlin', 'channel_label' => 'Team inbox']),
            'verification' => $this->renderer->renderVerification('428913', $locale),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sample(string $key, string $locale): array
    {
        $values = [
            'staff' => 'Volodymyr',
            'store' => 'Kaffee Rösterei',
            'stores' => '«Kaffee Rösterei»',
            'tenant' => 'Kaffee Rösterei GmbH',
            'case' => 'SUP-000001',
            'incident' => '#1042',
            'minutes' => '60',
            'hours' => '24',
            'inviter' => 'Anna Müller',
            'role' => $this->mailer->role('admin', $locale),
            'expires' => '2026-11-02',
            'average' => '1 240',
            'limit' => '1 000',
            'date' => '2026-11-25',
            'by' => trans('account.organization_deletion_by.owner', [], $locale),
            'added' => '— '.trans('account.roles.admin', [], $locale),
            'removed' => '—',
            'email' => 'a***@example.test',
            'parts' => '— '.trans('account.service_alert.parts.queue_event_inbox', [], $locale),
            'time' => '2026-10-26 09:00 UTC',
            'environment' => 'production',
        ];
        $link = match (true) {
            str_starts_with($key, 'account.staff_'), str_starts_with($key, 'account.service_alert') => $this->owner('/service'),
            default => $this->app('/app/settings/profile?token=preview'),
        };
        $placeholders = [];
        preg_match_all('/:([a-z_]+)/', trans("{$key}.subject", [], 'en').' '.trans("{$key}.body", [], 'en'), $placeholders);

        $replace = ['link' => $link];

        foreach (array_unique($placeholders[1]) as $name) {
            $replace[$name] ??= $values[$name] ?? 'Example';
        }

        return $replace;
    }

    private function app(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function owner(string $path): string
    {
        return rtrim((string) config('watchdog.site.owner_url'), '/').$path;
    }
}
