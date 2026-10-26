<?php

namespace App\Support\Platform;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reminders to a customer organisation from the owner panel (ADR 0047). The staff member picks the recipients among
 * the organisation's members and edits the letter of each language before sending; a letter can be saved as the
 * template of its topic and language. Templates and letters are plain text with the placeholders `:tenant` (the
 * organisation's name) and `:link` (its billing page). Every sent letter is kept in full and shown in the customer card.
 */
class TenantReminders
{
    public const TOPICS = ['subscription', 'payment', 'other'];

    public const PLACEHOLDERS = [':tenant', ':link'];

    public const REPEAT_MINUTES = 10;

    public const SUBJECT_MAX = 150;

    public const BODY_MAX = 10000;

    public const HISTORY_LIMIT = 100;

    public function __construct(
        private readonly AccountMailer $mailer,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * Everything the panel needs to write a reminder: members to pick from (the active owners are suggested), the
     * templates of the topic per language, and the letters as they would be sent to the chosen recipients.
     *
     * @param  list<string>|null  $recipientIds
     * @param  array<string, array{subject?: ?string, body?: ?string}>  $letters
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, string $topic, ?array $recipientIds, array $letters): array
    {
        $members = $this->members($tenant);
        $suggested = array_values(array_column(array_filter($members, fn (array $member): bool => $member['active'] && $member['role'] === 'owner'), 'user_id'));
        $chosen = $this->chosen($members, $recipientIds ?? $suggested);
        $templates = [];

        foreach (AccountMailer::LOCALES as $locale) {
            $templates[$locale] = $this->template($topic, $locale);
        }

        return [
            'members' => $members,
            'suggested_recipient_ids' => $suggested,
            'templates' => $templates,
            'placeholders' => self::PLACEHOLDERS,
            'letters' => array_map(fn (string $locale): array => ['locale' => $locale] + $this->render($tenant, $letters[$locale] ?? $templates[$locale]), $this->locales($chosen)),
            'last_sent_at' => $this->lastSent($tenant, $topic)?->toJSON(),
        ];
    }

    /**
     * @param  list<string>  $recipientIds
     * @param  array<string, array{subject?: ?string, body?: ?string}>  $letters  per language of the recipients
     * @return array<string, mixed> the stored reminder
     */
    public function send(Tenant $tenant, string $topic, array $recipientIds, array $letters, string $staffId): array
    {
        $members = $this->members($tenant);

        if (array_filter($members, fn (array $member): bool => $member['active']) === []) {
            throw new ReminderRejected('no_recipients');
        }

        $recipients = $this->chosen($members, $recipientIds);

        if ($recipients === [] || count($recipients) !== count(array_unique($recipientIds))) {
            throw new ReminderRejected('recipients_invalid', 422);
        }

        $rendered = [];

        foreach ($this->locales($recipients) as $locale) {
            $letter = $this->validLetter($letters[$locale] ?? null, $locale);
            $rendered[$locale] = $this->render($tenant, $letter);
        }

        $last = $this->lastSent($tenant, $topic);

        if ($last !== null && $last->greaterThan(Carbon::now()->subMinutes(self::REPEAT_MINUTES))) {
            throw new ReminderRejected('reminder_recently_sent');
        }

        $reminderId = (string) Str::uuid7();
        DB::table('tenant_reminders')->insert(['id' => $reminderId, 'tenant_id' => $tenant->id, 'topic' => $topic, 'sent_by' => $staffId, 'created_at' => Carbon::now()]);

        foreach ($recipients as $recipient) {
            $letter = $rendered[$recipient['locale']];
            $this->mailer->deliver($recipient['email'], $letter['subject'], $letter['body'], $recipient['locale']);
            DB::table('tenant_reminder_messages')->insert([
                'id' => (string) Str::uuid7(),
                'reminder_id' => $reminderId,
                'tenant_id' => $tenant->id,
                'user_id' => $recipient['user_id'],
                'email' => $recipient['email'],
                'locale' => $recipient['locale'],
                'subject' => $letter['subject'],
                'body' => $letter['body'],
                'sent_at' => Carbon::now(),
            ]);
        }

        $this->audit->record($staffId, 'tenant.reminder_sent', 'tenant', $tenant->id, PlatformAudit::RESULT_SUCCEEDED, [
            'reminder_id' => $reminderId,
            'topic' => $topic,
            'recipient_user_ids' => array_column($recipients, 'user_id'),
            'subjects' => array_map(fn (array $letter): string => $letter['subject'], $rendered),
        ], null, $tenant->id);

        return $this->history($tenant, $reminderId)[0];
    }

    /**
     * @param  array{subject?: ?string, body?: ?string}  $letter
     * @return array{subject: string, body: string, customized: bool}
     */
    public function saveTemplate(string $staffId, string $topic, string $locale, array $letter): array
    {
        $letter = $this->validLetter($letter, $locale);
        $before = $this->template($topic, $locale);
        DB::table('reminder_templates')->updateOrInsert(
            ['topic' => $topic, 'locale' => $locale],
            ['subject' => $letter['subject'], 'body' => $letter['body'], 'updated_by' => $staffId, 'updated_at' => Carbon::now()],
        );
        $this->audit->record($staffId, 'reminder.template_saved', 'reminder_template', $topic.':'.$locale, PlatformAudit::RESULT_SUCCEEDED, [
            'topic' => $topic,
            'locale' => $locale,
            'before' => ['subject' => $before['subject'], 'body' => $before['body']],
            'after' => $letter,
        ]);

        return $this->template($topic, $locale);
    }

    /**
     * Sent reminders of the organisation, newest first, each with who sent it and every letter as it was sent.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Tenant $tenant, ?string $reminderId = null): array
    {
        $reminders = DB::table('tenant_reminders as r')
            ->leftJoin('platform_staff as s', 's.id', '=', 'r.sent_by')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('r.tenant_id', $tenant->id)
            ->when($reminderId !== null, fn ($query) => $query->where('r.id', $reminderId))
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['r.id', 'r.topic', 'r.created_at', 'u.name as sender_name', 'u.email as sender_email']);
        $messages = DB::table('tenant_reminder_messages')
            ->where('tenant_id', $tenant->id)
            ->whereIn('reminder_id', $reminders->pluck('id'))
            ->orderBy('email')
            ->get()
            ->groupBy('reminder_id');

        return $reminders->map(fn (object $reminder): array => [
            'id' => $reminder->id,
            'topic' => $reminder->topic,
            'sent_at' => Carbon::parse($reminder->created_at)->toJSON(),
            'sender' => $reminder->sender_email === null ? null : ['name' => $reminder->sender_name, 'email' => $reminder->sender_email],
            'messages' => $messages->get($reminder->id, collect())->map(fn (object $message): array => [
                'email' => $message->email,
                'locale' => $message->locale,
                'subject' => $message->subject,
                'body' => $message->body,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array{subject: string, body: string, customized: bool}
     */
    public function template(string $topic, string $locale): array
    {
        $saved = DB::table('reminder_templates')->where('topic', $topic)->where('locale', $locale)->first(['subject', 'body']);

        return $saved !== null
            ? ['subject' => (string) $saved->subject, 'body' => (string) $saved->body, 'customized' => true]
            : ['subject' => trans("account.reminder_{$topic}.subject", [], $locale), 'body' => trans("account.reminder_{$topic}.body", [], $locale), 'customized' => false];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function validLetter(mixed $letter, string $locale): array
    {
        $subject = is_array($letter) ? trim((string) ($letter['subject'] ?? '')) : '';
        $body = is_array($letter) ? trim((string) ($letter['body'] ?? '')) : '';

        if (mb_strlen($subject) < 3 || mb_strlen($subject) > self::SUBJECT_MAX || mb_strlen($body) < 10 || mb_strlen($body) > self::BODY_MAX) {
            throw new ReminderRejected('letter_invalid', 422, $locale);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @param  array{subject?: ?string, body?: ?string}  $letter
     * @return array{subject: string, body: string}
     */
    private function render(Tenant $tenant, array $letter): array
    {
        $values = [':tenant' => (string) $tenant->name, ':link' => $this->mailer->link('/app/settings/billing')];

        return [
            'subject' => strtr(trim((string) ($letter['subject'] ?? '')), $values),
            'body' => strtr(trim((string) ($letter['body'] ?? '')), $values),
        ];
    }

    /**
     * @return list<array{user_id: string, name: string, email: string, locale: string, role: string, active: bool}>
     */
    private function members(Tenant $tenant): array
    {
        $roles = Membership::query()->where('tenant_id', $tenant->id)->pluck('role', 'user_id');

        return User::query()->whereIn('id', $roles->keys())->orderBy('email')->get()
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'locale' => in_array($user->locale, AccountMailer::LOCALES, true) ? $user->locale : 'en',
                'role' => (string) $roles[$user->id],
                'active' => $user->disabled_at === null,
            ])->values()->all();
    }

    /**
     * @param  list<array{user_id: string, active: bool}>  $members
     * @param  list<string>  $ids
     * @return list<array{user_id: string, name: string, email: string, locale: string, role: string, active: bool}>
     */
    private function chosen(array $members, array $ids): array
    {
        return array_values(array_filter($members, fn (array $member): bool => $member['active'] && in_array($member['user_id'], $ids, true)));
    }

    /**
     * @param  list<array{locale: string}>  $recipients
     * @return list<string>
     */
    private function locales(array $recipients): array
    {
        return array_values(array_intersect(AccountMailer::LOCALES, array_column($recipients, 'locale')));
    }

    private function lastSent(Tenant $tenant, string $topic): ?Carbon
    {
        $value = DB::table('tenant_reminders')->where('tenant_id', $tenant->id)->where('topic', $topic)->max('created_at');

        return $value === null ? null : Carbon::parse($value);
    }
}
