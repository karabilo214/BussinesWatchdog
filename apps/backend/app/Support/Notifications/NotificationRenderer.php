<?php

namespace App\Support\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;

class NotificationRenderer
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function render(array $content): RenderedNotification
    {
        $locale = in_array($content['locale'] ?? null, NotificationPreferences::LOCALES, true)
            ? $content['locale']
            : 'en';

        if (($content['kind'] ?? null) === NotificationDelivery::KIND_TEST) {
            return new RenderedNotification(
                $this->t('test.subject', [], $locale),
                $this->t('test.body', ['label' => (string) ($content['channel_label'] ?? '')], $locale),
            );
        }

        return $content['kind'] === NotificationDelivery::KIND_INCIDENT_RECOVERED
            ? $this->renderRecovery($content, $locale)
            : $this->renderIncident($content, $locale);
    }

    public function renderVerification(string $code, string $locale): RenderedNotification
    {
        $locale = in_array($locale, NotificationPreferences::LOCALES, true) ? $locale : 'en';

        return new RenderedNotification(
            $this->t('verification.subject', [], $locale),
            $this->t('verification.body', ['code' => $code, 'minutes' => 15], $locale),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function renderIncident(array $content, string $locale): RenderedNotification
    {
        $order = $this->orderLabel($content, $locale);
        $severity = $this->t('severity.'.$content['severity'], [], $locale);
        $lines = [
            $this->t('store', ['store' => $content['store_name']], $locale),
            $this->t('severity_line', ['severity' => $severity], $locale),
            '',
            $this->fact($content, $order, $locale),
        ];

        if ($content['amount_minor'] !== null && $content['currency'] !== null && $content['currency_exponent'] !== null) {
            $lines[] = $this->t('amount', [
                'amount' => MinorUnits::format((string) $content['amount_minor'], (int) $content['currency_exponent']),
                'currency' => $content['currency'],
            ], $locale);
        }

        $lines[] = $this->possibleStart($content, $locale);
        $lines[] = $this->t('source.'.$content['source'], [], $locale);
        $lines[] = $this->t('checked_steps', [
            'steps' => implode(', ', array_map(
                fn (string $step): string => $this->t('step.'.$step, [], $locale),
                $content['checked_steps'],
            )),
        ], $locale);
        $lines[] = $this->t('what_to_check.'.$content['component'], [], $locale, 'what_to_check.default');
        $lines[] = '';
        $lines[] = $this->t('link', ['link' => $content['link']], $locale);

        $subjectKey = $content['kind'] === NotificationDelivery::KIND_INCIDENT_REOPENED
            ? 'subject.reopened'
            : 'subject.opened';

        return new RenderedNotification(
            $this->t($subjectKey, ['store' => $content['store_name'], 'severity' => $severity], $locale),
            implode("\n", $lines),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function renderRecovery(array $content, string $locale): RenderedNotification
    {
        $order = $this->orderLabel($content, $locale);
        $from = $this->time($content['possible_start_to'], $content['timezone']);
        $to = $content['recovered_at'] !== null
            ? $this->time($content['recovered_at'], $content['timezone'])
            : $this->t('unknown', [], $locale);

        $lines = [
            $this->t('store', ['store' => $content['store_name']], $locale),
            '',
            $this->t('recovery.fact', ['order' => $order], $locale),
            $this->t('recovery.duration', ['from' => $from, 'to' => $to], $locale),
            $this->t('recovery.restored', [], $locale),
            '',
            $this->t('link', ['link' => $content['link']], $locale),
        ];

        return new RenderedNotification(
            $this->t('subject.recovered', ['store' => $content['store_name']], $locale),
            implode("\n", $lines),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function fact(array $content, string $order, string $locale): string
    {
        return $this->t('fact.'.$content['rule_code'], ['order' => $order], $locale, 'fact.default');
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function possibleStart(array $content, string $locale): string
    {
        $to = $this->time($content['possible_start_to'], $content['timezone']);

        if ($content['possible_start_from'] === null) {
            return $this->t('possible_start.not_later_than', ['to' => $to], $locale);
        }

        return $this->t('possible_start.between', [
            'from' => $this->time($content['possible_start_from'], $content['timezone']),
            'to' => $to,
        ], $locale);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function orderLabel(array $content, string $locale): string
    {
        return $content['order_number'] !== null
            ? (string) $content['order_number']
            : $this->t('order_unknown', [], $locale);
    }

    private function time(?string $iso, string $timezone): string
    {
        if ($iso === null) {
            return '—';
        }

        return Carbon::parse($iso)->setTimezone($timezone)->format('Y-m-d H:i T');
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function t(string $key, array $replace, string $locale, ?string $fallbackKey = null): string
    {
        $fullKey = 'notifications.'.$key;
        $translated = trans($fullKey, $replace, $locale);

        if ($translated === $fullKey && $fallbackKey !== null) {
            return trans('notifications.'.$fallbackKey, $replace, $locale);
        }

        return $translated;
    }
}
