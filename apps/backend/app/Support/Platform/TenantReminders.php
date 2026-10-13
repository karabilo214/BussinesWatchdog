<?php

namespace App\Support\Platform;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TenantReminders
{
    public const TOPICS = ['subscription', 'payment', 'other'];

    public const REPEAT_MINUTES = 10;

    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * @param  array{subject?: ?string, message?: ?string}  $input
     * @return array{recipients: list<array{user_id: string, name: string, email: string, locale: string}>, letters: list<array{locale: string, subject: string, body: string}>, last_sent_at: ?string}
     */
    public function preview(Tenant $tenant, string $topic, array $input): array
    {
        $recipients = $this->recipients($tenant);
        $letters = [];

        foreach (array_values(array_unique(array_column($recipients, 'locale'))) as $locale) {
            $letters[] = ['locale' => $locale] + $this->letter($tenant, $topic, $locale, $input);
        }

        return ['recipients' => $recipients, 'letters' => $letters, 'last_sent_at' => $this->lastSent($tenant, $topic)?->toJSON()];
    }

    /**
     * @param  array{subject?: ?string, message?: ?string}  $input
     */
    public function send(Tenant $tenant, string $topic, array $input, string $staffId): int
    {
        $recipients = $this->recipients($tenant);

        if ($recipients === []) {
            throw new ReminderRejected('no_recipients');
        }

        $last = $this->lastSent($tenant, $topic);

        if ($last !== null && $last->greaterThan(Carbon::now()->subMinutes(self::REPEAT_MINUTES))) {
            throw new ReminderRejected('reminder_recently_sent');
        }

        $hashes = [];

        foreach ($recipients as $recipient) {
            $letter = $this->letter($tenant, $topic, $recipient['locale'], $input);
            $this->mailer->deliver($recipient['email'], $letter['subject'], $letter['body']);
            $hashes[$recipient['locale']] = hash('sha256', $letter['subject']."\n".$letter['body']);
        }

        $this->audit->record($staffId, 'tenant.reminder_sent', 'tenant', $tenant->id, PlatformAudit::RESULT_SUCCEEDED, [
            'topic' => $topic,
            'recipient_user_ids' => array_column($recipients, 'user_id'),
            'content_hashes' => $hashes,
        ], null, $tenant->id);

        return count($recipients);
    }

    /**
     * @param  array{subject?: ?string, message?: ?string}  $input
     * @return array{subject: string, body: string}
     */
    private function letter(Tenant $tenant, string $topic, string $locale, array $input): array
    {
        $replace = ['tenant' => $tenant->name, 'link' => $this->mailer->link('/app/settings/billing'), 'subject' => trim((string) ($input['subject'] ?? '')), 'message' => trim((string) ($input['message'] ?? ''))];

        return [
            'subject' => trans("account.reminder_{$topic}.subject", $replace, $locale),
            'body' => trans("account.reminder_{$topic}.body", $replace, $locale),
        ];
    }

    /**
     * @return list<array{user_id: string, name: string, email: string, locale: string}>
     */
    private function recipients(Tenant $tenant): array
    {
        $owners = Membership::query()->where('tenant_id', $tenant->id)->where('role', 'owner')->pluck('user_id');

        return User::query()->whereIn('id', $owners)->whereNull('disabled_at')->orderBy('email')->get()
            ->map(fn (User $user): array => ['user_id' => $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email, 'locale' => in_array($user->locale, AccountMailer::LOCALES, true) ? $user->locale : 'en'])
            ->values()->all();
    }

    private function lastSent(Tenant $tenant, string $topic): ?Carbon
    {
        $value = DB::table('platform_audit_log')->where('action', 'tenant.reminder_sent')->where('target_id', $tenant->id)->where('result', PlatformAudit::RESULT_SUCCEEDED)
            ->get(['created_at', 'sanitized_changes'])
            ->filter(fn (object $row): bool => (json_decode((string) $row->sanitized_changes, true)['topic'] ?? null) === $topic)
            ->max('created_at');

        return $value === null ? null : Carbon::parse($value);
    }
}
