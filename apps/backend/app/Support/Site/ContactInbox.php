<?php

namespace App\Support\Site;

use App\Mail\NotificationMessageMail;
use App\Models\ContactRequest;
use App\Models\ContactRequestReply;
use App\Models\PlatformStaff;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Messages sent through the contact form (ADR 0039): stored for the owner panel, announced by email to the address in
 * `watchdog.site.contact_notify` (or to every active platform owner) by a scheduled command, so the form never waits
 * for the mail server; closed messages are deleted after the retention period.
 */
class ContactInbox
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @param  array{name: string, email: string, company?: ?string, message: string}  $input
     */
    public function receive(string $locale, array $input): ContactRequest
    {
        return ContactRequest::query()->create([
            'locale' => $locale,
            'name' => trim($input['name']),
            'email' => mb_strtolower(trim($input['email'])),
            'company' => ($company = trim((string) ($input['company'] ?? ''))) === '' ? null : $company,
            'message' => trim($input['message']),
            'status' => ContactRequest::STATUS_NEW,
        ]);
    }

    /**
     * @param  array{status?: ?string, cursor?: ?string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string, counts: array<string, int>}
     */
    public function list(array $filters): array
    {
        $rows = ContactRequest::query()
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['cursor'] ?? null) !== null, fn ($query) => $query->where('id', '<', $filters['cursor']))
            ->orderByDesc('id')
            ->limit(51)
            ->get();
        $page = $rows->take(50);
        $counts = ContactRequest::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return [
            'data' => $page->map(fn (ContactRequest $request): array => $this->dto($request, false))->values()->all(),
            'next_cursor' => $rows->count() > 50 ? $page->last()?->id : null,
            'counts' => array_map(fn (string $status): int => (int) ($counts[$status] ?? 0), array_combine(ContactRequest::STATUSES, ContactRequest::STATUSES)),
        ];
    }

    public function find(string $id): ContactRequest
    {
        /** @var ContactRequest|null $request */
        $request = ContactRequest::query()->find($id);

        return $request ?? throw new SiteRejected('contact_request_not_found', 404);
    }

    /**
     * @param  array{status?: ?string, note?: ?string}  $input
     */
    public function update(string $staffId, string $id, array $input): ContactRequest
    {
        $request = $this->find($id);
        $changes = [];

        if (isset($input['status'])) {
            if (! in_array($input['status'], ContactRequest::STATUSES, true)) {
                throw new SiteRejected('status_invalid');
            }

            $changes['status'] = $input['status'];
            $changes['closed_at'] = $input['status'] === ContactRequest::STATUS_CLOSED ? ($request->closed_at ?? Carbon::now()) : null;
        }

        if (array_key_exists('note', $input)) {
            $note = trim((string) $input['note']);
            $changes['note'] = $note === '' ? null : mb_substr($note, 0, 2000);
        }

        $request->forceFill($changes + ['handled_by' => $staffId])->save();
        $this->audit->record($staffId, 'site.contact.updated', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, array_intersect_key($changes, ['status' => true]) + ['note_changed' => array_key_exists('note', $changes)]);

        return $request;
    }

    /**
     * @return list<array{code: string, subject: string, body: string}>
     */
    public function replyTemplates(ContactRequest $request): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code,
            'subject' => trans("site.contact.reply.templates.{$code}.subject", [], $request->locale),
            'body' => trans("site.contact.reply.templates.{$code}.body", [], $request->locale),
        ], ContactRequestReply::TEMPLATES);
    }

    /**
     * The letter as the person gets it: a greeting by name, the staff text, the signature and their original message.
     */
    public function letter(ContactRequest $request, string $body): string
    {
        return trans('site.contact.reply.letter', ['name' => $request->name, 'body' => trim($body), 'message' => $request->message, 'date' => $request->created_at?->format('d.m.Y H:i T')], $request->locale);
    }

    /**
     * @param  array{template: string, subject: string, body: string}  $input
     */
    public function reply(string $staffId, string $id, array $input): ContactRequestReply
    {
        $request = $this->find($id);
        $subject = trim($input['subject']);
        $body = trim($input['body']);

        if (! in_array($input['template'], ContactRequestReply::TEMPLATES, true)) {
            throw new SiteRejected('template_invalid');
        }

        if ($subject === '' || mb_strlen($subject) > 200 || mb_strlen($body) < 2 || mb_strlen($body) > 10000) {
            throw new SiteRejected('reply_invalid');
        }

        try {
            Mail::to($request->email)->send(new NotificationMessageMail($subject, $this->letter($request, $body)));
        } catch (Throwable $exception) {
            Log::warning('site.contact_reply_failed', ['contact_request_id' => $request->id, 'exception' => $exception::class]);

            throw new SiteRejected('reply_not_sent', 502);
        }

        $reply = DB::transaction(function () use ($staffId, $request, $input, $subject, $body): ContactRequestReply {
            $reply = ContactRequestReply::query()->create([
                'contact_request_id' => $request->id,
                'staff_id' => $staffId,
                'template' => $input['template'],
                'subject' => $subject,
                'body' => $body,
                'sent_at' => Carbon::now(),
            ]);
            $request->forceFill(['handled_by' => $staffId] + ($request->status === ContactRequest::STATUS_NEW ? ['status' => ContactRequest::STATUS_IN_PROGRESS] : []))->save();

            return $reply;
        });
        $this->audit->record($staffId, 'site.contact.replied', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['reply_id' => $reply->id, 'template' => $reply->template, 'content_hash' => hash('sha256', $subject."\n".$body)]);

        return $reply;
    }

    /**
     * @return array<string, mixed>
     */
    public function dto(ContactRequest $request, bool $full = true): array
    {
        $handler = $request->handled_by === null ? null : PlatformStaff::query()->with('user')->find($request->handled_by);

        return [
            'id' => $request->id,
            'locale' => $request->locale,
            'name' => $request->name,
            'email' => $request->email,
            'company' => $request->company,
            'message' => $full ? $request->message : Str::limit($request->message, 140),
            'status' => $request->status,
            'note' => $full ? $request->note : null,
            'handled_by' => $handler?->user?->name,
            'created_at' => $request->created_at?->toJSON(),
            'updated_at' => $request->updated_at?->toJSON(),
            'closed_at' => $request->closed_at?->toJSON(),
        ] + ($full ? ['replies' => $this->replies($request), 'reply_templates' => $this->replyTemplates($request)] : []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function replies(ContactRequest $request): array
    {
        $replies = ContactRequestReply::query()->where('contact_request_id', $request->id)->orderBy('sent_at')->orderBy('id')->get();
        $staff = PlatformStaff::query()->with('user')->whereIn('id', $replies->pluck('staff_id')->filter()->unique())->get()->keyBy('id');

        return $replies->map(fn (ContactRequestReply $reply): array => [
            'id' => $reply->id,
            'template' => $reply->template,
            'subject' => $reply->subject,
            'body' => $reply->body,
            'sent_by' => $reply->staff_id === null ? null : $staff->get($reply->staff_id)?->user?->name,
            'sent_at' => $reply->sent_at->toJSON(),
        ])->values()->all();
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function notifyPending(): array
    {
        $recipients = $this->recipients();
        $result = ['sent' => 0, 'failed' => 0];

        if ($recipients === []) {
            return $result;
        }

        $pending = ContactRequest::query()->whereNull('notified_at')->where('created_at', '>', Carbon::now()->subDays(3))->orderBy('id')->limit(50)->get();

        foreach ($pending as $request) {
            $claimed = ContactRequest::query()->whereKey($request->id)->whereNull('notified_at')->update(['notified_at' => Carbon::now()]);

            if ($claimed === 0) {
                continue;
            }

            try {
                Mail::to($recipients)->send(new NotificationMessageMail(
                    trans('site.contact.mail.subject', ['name' => $request->name], 'ru'),
                    trans('site.contact.mail.body', [
                        'name' => $request->name,
                        'email' => $request->email,
                        'company' => $request->company ?? '—',
                        'locale' => $request->locale,
                        'message' => $request->message,
                        'link' => rtrim((string) config('watchdog.site.owner_url'), '/').'/contact-requests/'.$request->id,
                    ], 'ru'),
                ));
                $result['sent']++;
            } catch (Throwable $exception) {
                ContactRequest::query()->whereKey($request->id)->update(['notified_at' => null]);
                Log::warning('site.contact_notify_failed', ['contact_request_id' => $request->id, 'exception' => $exception::class]);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function purge(): int
    {
        $days = (int) config('watchdog.site.contact_retention_days');

        return ContactRequest::query()->where('status', ContactRequest::STATUS_CLOSED)->where('closed_at', '<', Carbon::now()->subDays($days))->delete();
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = trim((string) config('watchdog.site.contact_notify'));

        if ($configured !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured)), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
        }

        return PlatformStaff::query()
            ->with('user')
            ->where('role', PlatformStaff::ROLE_OWNER)
            ->get()
            ->filter(fn (PlatformStaff $staff): bool => $staff->active() && $staff->user?->email !== null)
            ->map(fn (PlatformStaff $staff): string => (string) $staff->user->email)
            ->values()
            ->all();
    }
}
