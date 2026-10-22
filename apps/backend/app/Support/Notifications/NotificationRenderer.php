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

        if (($content['kind'] ?? null) === NotificationDelivery::KIND_DAILY_DIGEST) {
            return $this->renderDigest($content, $locale);
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
        $lines[] = $this->firstStep($content, $locale) ?? $this->t('what_to_check.'.$content['component'], [], $locale, 'what_to_check.default');
        $lines[] = '';
        $lines[] = $this->t('link', ['link' => $content['link']], $locale);

        $subjectKey = match ($content['kind']) {
            NotificationDelivery::KIND_INCIDENT_REOPENED => 'subject.reopened',
            NotificationDelivery::KIND_INCIDENT_REMINDER => 'subject.reminder',
            default => 'subject.opened',
        };
        $subjectReplace = ['store' => $content['store_name'], 'severity' => $severity];

        return new RenderedNotification(
            $this->familyText($content, $subjectKey, $subjectReplace, $locale),
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
            $this->familyText($content, 'recovery.fact', $this->factReplace($content, $order), $locale),
            $this->t('recovery.duration', ['from' => $from, 'to' => $to], $locale),
            $this->familyText($content, 'recovery.restored', [], $locale),
            '',
            $this->t('link', ['link' => $content['link']], $locale),
        ];

        return new RenderedNotification(
            $this->familyText($content, 'subject.recovered', ['store' => $content['store_name']], $locale),
            implode("\n", $lines),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function renderDigest(array $content, string $locale): RenderedNotification
    {
        $timezone = (string) $content['timezone'];
        $lines = [
            $this->t('digest.period', [
                'from' => $this->time($content['period_from'], $timezone),
                'to' => $this->time($content['period_to'], $timezone),
            ], $locale),
            $this->t('digest.verdict.'.$content['verdict'], [], $locale),
        ];

        if ($content['incidents'] !== []) {
            $lines[] = '';
            $lines[] = $this->t('digest.incidents', [], $locale);

            foreach ($content['incidents'] as $incident) {
                $title = $this->familyText($incident, 'subject.opened', [
                    'store' => $incident['store_name'],
                    'severity' => $this->t('severity.'.$incident['severity'], [], $locale),
                ], $locale);
                $lines[] = '• '.$this->t('digest.incident', ['title' => $title, 'since' => $this->time($incident['first_seen_at'], $timezone)], $locale);
                $lines[] = '  '.$incident['link'];
            }

            if ($content['more_incidents'] > 0) {
                $lines[] = $this->t('digest.more_incidents', ['n' => $content['more_incidents']], $locale);
            }
        }

        if ($content['discrepancies'] !== [] || $content['unknown_amount_incidents'] > 0) {
            $lines[] = '';
            $lines[] = $this->t('digest.discrepancies', [], $locale);

            foreach ($content['discrepancies'] as $row) {
                $amount = $row['currency_exponent'] !== null
                    ? MinorUnits::format((string) $row['total_minor'], (int) $row['currency_exponent']).' '.$row['currency']
                    : $this->t('digest.minor_units', ['amount' => $row['total_minor'], 'currency' => $row['currency']], $locale);
                $lines[] = '• '.$this->t('digest.discrepancy', [
                    'mode' => $this->t('digest.mode.'.($row['mode'] ?? 'unconfirmed'), [], $locale, 'digest.mode.unconfirmed'),
                    'component' => $this->t('digest.component.'.$row['component'], [], $locale, 'digest.component.default'),
                    'amount' => $amount,
                    'n' => $row['incident_count'],
                ], $locale);
            }

            if ($content['unknown_amount_incidents'] > 0) {
                $lines[] = $this->t('digest.unknown_amounts', ['n' => $content['unknown_amount_incidents']], $locale);
            }
        }

        foreach ($content['stores'] as $store) {
            $lines[] = '';
            $lines[] = $store['name'];

            if ($store['paused']) {
                $lines[] = $this->t('digest.gap.paused', [], $locale);

                continue;
            }

            $active = array_filter($store['active']);
            $lines[] = $active === []
                ? $this->t('digest.store.no_active', [], $locale)
                : $this->t('digest.store.active', ['list' => implode(', ', array_map(
                    fn (string $severity, int $count): string => $this->t('severity.'.$severity, [], $locale).': '.$count,
                    array_keys($active),
                    $active,
                ))], $locale);
            $lines[] = $this->t('digest.store.changes', [
                'opened' => $store['opened'],
                'resolved' => $store['resolved'],
            ], $locale);
            $lines[] = $this->t('digest.store.checks', $store['checks'], $locale);
            $lines[] = $store['last_successful_check_at'] !== null
                ? $this->t('digest.store.last_check', ['time' => $this->time($store['last_successful_check_at'], $timezone)], $locale)
                : $this->t('digest.store.no_successful_check', [], $locale);

            foreach ($store['gaps'] as $gap) {
                $lines[] = '  – '.$this->t('digest.gap.'.$gap, [], $locale, 'digest.gap.default');
            }
        }

        $lines[] = '';
        $lines[] = $this->t('digest.note', [], $locale);
        $lines[] = $this->t('digest.link', ['link' => $content['link']], $locale);

        return new RenderedNotification(
            $this->t('digest.subject.'.$content['verdict'], [], $locale),
            implode("\n", $lines),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function fact(array $content, string $order, string $locale): string
    {
        return $this->t('fact.'.$content['rule_code'], $this->factReplace($content, $order), $locale, 'fact.default');
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, string>
     */
    private function factReplace(array $content, string $order): array
    {
        $replace = [
            'order' => $order,
            'method' => (string) ($content['payment_method'] ?? '—'),
            'count' => (string) ($content['failure_streak'] ?? '—'),
        ];

        foreach ((array) ($content['fact_params'] ?? []) as $key => $value) {
            $replace[(string) $key] = str_ends_with((string) $key, '_at')
                ? $this->time(is_string($value) ? $value : null, (string) $content['timezone'])
                : (string) $value;
        }

        return $replace;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, string>  $replace
     */
    private function familyText(array $content, string $key, array $replace, string $locale): string
    {
        $family = $content['family'] ?? null;

        if (is_string($family) && $family !== '' && $family !== 'money') {
            $component = (string) ($content['component'] ?? '');
            $familyKey = 'family.'.$family.'.'.$key;
            $componentKey = 'family.'.$family.'.components.'.$component.'.'.$key;

            return $this->t($componentKey, $replace, $locale, trans('notifications.'.$familyKey, [], $locale) === 'notifications.'.$familyKey ? $key : $familyKey);
        }

        return $this->t($key, $replace, $locale);
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
    /**
     * The first step of the incident's explanation, worded for this case (a known payment id, the provider); null keeps
     * the general advice for incidents without an explanation and for notifications written before it existed.
     *
     * @param  array<string, mixed>  $content
     */
    private function firstStep(array $content, string $locale): ?string
    {
        $step = $content['first_step'] ?? null;

        if (! is_string($step) || trans('notifications.first_step.'.$step, [], $locale) === 'notifications.first_step.'.$step) {
            return null;
        }

        $params = is_array($content['first_step_params'] ?? null) ? $content['first_step_params'] : [];
        $provider = is_string($params['provider'] ?? null) ? $params['provider'] : 'default';
        $providerAt = $this->t('provider_at.'.$provider, [], $locale, 'provider_at.default');

        return $this->t('first_step.'.$step, ['provider_at' => $providerAt, 'reference' => (string) ($params['reference'] ?? ''), 'capture' => (string) ($params['capture'] ?? '')], $locale);
    }

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
