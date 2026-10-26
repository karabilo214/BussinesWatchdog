<?php

namespace App\Support\Site;

use App\Mail\NotificationMessageMail;
use App\Models\ContactRequest;
use App\Models\ContactRequestNote;
use App\Models\ContactRequestReply;
use App\Models\PlatformStaff;
use App\Support\Platform\PlatformAudit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Messages sent through the contact form (ADR 0039): stored for the owner panel, announced by email to the address in
 * `watchdog.site.contact_notify` (or to every active platform owner) by a scheduled command, so the form never waits
 * for the mail server; closed messages are deleted after the retention period. One file may come with a message: only
 * the formats of `watchdog.site.contact_attachment_types`, recognised by content and cleaned by SafeFiles (images are
 * encoded again), kept privately and given only to staff as a download.
 */
class ContactInbox
{
    public function __construct(private readonly PlatformAudit $audit, private readonly SafeFiles $files) {}

    /**
     * @param  array{name: string, email: string, company?: ?string, message: string}  $input
     */
    public function receive(string $locale, array $input, ?UploadedFile $attachment = null): ContactRequest
    {
        $stored = $attachment === null ? [] : $this->storeAttachment($attachment);

        try {
            return ContactRequest::query()->create([
                'locale' => $locale,
                'name' => trim($input['name']),
                'email' => mb_strtolower(trim($input['email'])),
                'company' => ($company = trim((string) ($input['company'] ?? ''))) === '' ? null : $company,
                'message' => trim($input['message']),
                'status' => ContactRequest::STATUS_NEW,
            ] + $stored);
        } catch (Throwable $exception) {
            if ($stored !== []) {
                $this->disk()->delete($stored['attachment_key']);
            }

            throw $exception;
        }
    }

    /**
     * The attachment formats and size in force: the configuration, never above what PHP itself accepts.
     *
     * @return array{types: list<string>, extensions: list<string>, content_types: list<string>, max_bytes: int, max_side: int, max_pixels: int, resize_max_side: int}
     */
    public function attachmentLimits(): array
    {
        $types = array_values(array_intersect(array_keys(SafeFiles::FORMATS), (array) config('watchdog.site.contact_attachment_types')));

        return [
            'types' => $types,
            'extensions' => array_map(fn (string $type): string => SafeFiles::FORMATS[$type]['extension'], $types),
            'content_types' => array_map(fn (string $type): string => SafeFiles::FORMATS[$type]['content_type'], $types),
            'max_bytes' => max(1, min((int) config('watchdog.site.contact_attachment_max_bytes'), SafeFiles::phpUploadLimit())),
            'max_side' => max(1, (int) config('watchdog.site.media_max_side')),
            'max_pixels' => max(1, (int) config('watchdog.site.media_max_pixels')),
            'resize_max_side' => max(0, (int) config('watchdog.site.media_resize_max_side')),
        ];
    }

    /**
     * @return array{body: string, name: string, content_type: string}
     */
    public function attachment(string $staffId, string $id): array
    {
        $request = $this->find($id);
        $body = $request->attachment_key === null ? null : $this->disk()->get($request->attachment_key);

        if ($body === null) {
            throw new SiteRejected('attachment_not_found', 404);
        }

        $this->audit->record($staffId, 'site.contact.attachment_downloaded', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['bytes' => $request->attachment_bytes]);

        return ['body' => $body, 'name' => (string) $request->attachment_name, 'content_type' => (string) $request->attachment_content_type];
    }

    /**
     * @return array{attachment_key: string, attachment_name: string, attachment_content_type: string, attachment_bytes: int}
     */
    private function storeAttachment(UploadedFile $file): array
    {
        $limits = $this->attachmentLimits();
        $clean = $this->files->clean($file, $limits['types'], $limits['max_bytes'], $limits);
        $key = 'contact-attachments/'.Str::uuid7().'.'.$clean['extension'];
        $name = trim((string) preg_replace('/[\\\\\/"%]+/', '_', pathinfo($this->files->originalName($file), PATHINFO_FILENAME)));

        try {
            $this->disk()->put($key, $clean['bytes'], ['visibility' => 'private', 'ContentType' => $clean['content_type']]);
        } catch (Throwable $exception) {
            report($exception);

            throw new SiteRejected('media_storage_unavailable', 503);
        }

        return [
            'attachment_key' => $key,
            'attachment_name' => mb_substr($name === '' ? 'file' : $name, 0, 190).'.'.$clean['extension'],
            'attachment_content_type' => $clean['content_type'],
            'attachment_bytes' => strlen($clean['bytes']),
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.site.contact_attachment_disk'));
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
     * @param  array{status?: ?string}  $input
     */
    public function update(string $staffId, string $id, array $input): ContactRequest
    {
        $request = $this->find($id);
        $previousStatus = $request->status;
        $previousHandler = $request->handled_by;
        $changes = [];

        if (isset($input['status'])) {
            if (! in_array($input['status'], ContactRequest::STATUSES, true)) {
                throw new SiteRejected('status_invalid');
            }

            $changes['status'] = $input['status'];
            $changes['closed_at'] = $input['status'] === ContactRequest::STATUS_CLOSED ? ($request->closed_at ?? Carbon::now()) : null;
        }

        $request->forceFill($changes + ['handled_by' => $staffId])->save();
        $this->audit->record($staffId, 'site.contact.updated', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => ['status' => $previousStatus, 'handled_by' => $previousHandler], 'after' => ['status' => $request->status, 'handled_by' => $request->handled_by]]);

        return $request;
    }

    public function addNote(string $staffId, string $id, string $body): ContactRequestNote
    {
        $request = $this->find($id);
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 2000) {
            throw new SiteRejected('note_invalid');
        }

        $note = ContactRequestNote::query()->create(['contact_request_id' => $request->id, 'staff_id' => $staffId, 'body' => $body]);
        $this->audit->record($staffId, 'site.contact.noted', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['note_id' => $note->id]);

        return $note;
    }

    /**
     * The author edits their own note (ADR 0049); the audit keeps the text before and after.
     */
    public function editNote(string $staffId, string $id, string $noteId, string $body): ContactRequestNote
    {
        $request = $this->find($id);
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 2000) {
            throw new SiteRejected('note_invalid');
        }

        /** @var ContactRequestNote|null $note */
        $note = Str::isUuid($noteId) ? ContactRequestNote::query()->where('contact_request_id', $request->id)->whereKey($noteId)->first() : null;

        if ($note === null) {
            throw new SiteRejected('note_not_found', 404);
        }

        if ($note->staff_id !== $staffId) {
            throw new SiteRejected('note_not_yours', 403);
        }

        $before = $note->body;
        $note->forceFill(['body' => $body, 'edited_at' => Carbon::now()])->save();
        $this->audit->record($staffId, 'site.contact.note_edited', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['note_id' => $note->id, 'before' => $before, 'after' => $body]);

        return $note;
    }

    /**
     * Reply templates of the request's language: saved by staff, otherwise the built-in text.
     *
     * @return list<array{code: string, subject: string, body: string, customized: bool}>
     */
    public function replyTemplates(ContactRequest $request): array
    {
        return array_map(fn (string $code): array => ['code' => $code] + $this->replyTemplate($code, (string) $request->locale), ContactRequestReply::TEMPLATES);
    }

    /**
     * @return array{subject: string, body: string, customized: bool}
     */
    public function replyTemplate(string $code, string $locale): array
    {
        $saved = DB::table('contact_reply_templates')->where('code', $code)->where('locale', $locale)->first(['subject', 'body']);

        return $saved !== null
            ? ['subject' => (string) $saved->subject, 'body' => (string) $saved->body, 'customized' => true]
            : ['subject' => trans("site.contact.reply.templates.{$code}.subject", [], $locale), 'body' => trans("site.contact.reply.templates.{$code}.body", [], $locale), 'customized' => false];
    }

    /**
     * "Save template" next to "Send" (ADR 0049): the edited subject and text become the template of that code and
     * language for every request. Audited with the text before and after.
     *
     * @return array{subject: string, body: string, customized: bool}
     */
    public function saveReplyTemplate(string $staffId, string $code, string $locale, string $subject, string $body): array
    {
        $subject = trim($subject);
        $body = trim($body);

        if (! in_array($code, ContactRequestReply::TEMPLATES, true)) {
            throw new SiteRejected('template_invalid', 404);
        }

        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200 || $body === '' || mb_strlen($body) > 10000) {
            throw new SiteRejected('template_text_invalid');
        }

        $before = $this->replyTemplate($code, $locale);
        DB::table('contact_reply_templates')->updateOrInsert(['code' => $code, 'locale' => $locale], ['subject' => $subject, 'body' => $body, 'updated_by' => $staffId, 'updated_at' => Carbon::now()]);
        $this->audit->record($staffId, 'site.contact.template_saved', 'contact_reply_template', $code.':'.$locale, PlatformAudit::RESULT_SUCCEEDED, [
            'before' => ['subject' => $before['subject'], 'body' => $before['body']],
            'after' => ['subject' => $subject, 'body' => $body],
        ]);

        return $this->replyTemplate($code, $locale);
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
        $previousStatus = $request->status;
        $previousHandler = $request->handled_by;
        $subject = trim($input['subject']);
        $body = trim($input['body']);

        if (! in_array($input['template'], ContactRequestReply::TEMPLATES, true)) {
            throw new SiteRejected('template_invalid');
        }

        if ($subject === '' || mb_strlen($subject) > 200 || mb_strlen($body) < 2 || mb_strlen($body) > 10000) {
            throw new SiteRejected('reply_invalid');
        }

        try {
            Mail::to($request->email)->send(new NotificationMessageMail($subject, $this->letter($request, $body), (string) $request->locale, NotificationMessageMail::AUDIENCE_VISITOR));
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
        $this->audit->record($staffId, 'site.contact.replied', 'contact_request', $request->id, PlatformAudit::RESULT_SUCCEEDED, ['reply_id' => $reply->id, 'template' => $reply->template, 'content_hash' => hash('sha256', $subject."\n".$body), 'before' => ['status' => $previousStatus, 'handled_by' => $previousHandler], 'after' => ['status' => $request->status, 'handled_by' => $request->handled_by]]);

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
            'handled_by' => $handler?->user?->name,
            'created_at' => $request->created_at?->toJSON(),
            'updated_at' => $request->updated_at?->toJSON(),
            'closed_at' => $request->closed_at?->toJSON(),
            'attachment' => $request->attachment_key === null ? null : ['name' => $request->attachment_name, 'content_type' => $request->attachment_content_type, 'bytes' => $request->attachment_bytes],
        ] + ($full ? ['notes' => $this->notes($request), 'replies' => $this->replies($request), 'reply_templates' => $this->replyTemplates($request)] : []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notes(ContactRequest $request): array
    {
        $notes = ContactRequestNote::query()->where('contact_request_id', $request->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $staff = PlatformStaff::query()->with('user')->whereIn('id', $notes->pluck('staff_id')->filter()->unique())->get()->keyBy('id');

        return $notes->map(fn (ContactRequestNote $note): array => [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->staff_id === null ? null : $staff->get($note->staff_id)?->user?->name,
            'author_staff_id' => $note->staff_id,
            'created_at' => $note->created_at?->toJSON(),
            'edited_at' => $note->edited_at?->toJSON(),
        ])->values()->all();
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
                        'attachment' => $request->attachment_name === null ? '—' : $request->attachment_name.' ('.max(1, (int) ceil($request->attachment_bytes / 1024)).' KB)',
                        'link' => rtrim((string) config('watchdog.site.owner_url'), '/').'/contact-requests/'.$request->id,
                    ], 'ru'),
                    'ru',
                    NotificationMessageMail::AUDIENCE_STAFF,
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
        $deleted = 0;

        ContactRequest::query()->where('status', ContactRequest::STATUS_CLOSED)->where('closed_at', '<', Carbon::now()->subDays($days))
            ->chunkById(200, function ($requests) use (&$deleted): void {
                $keys = $requests->pluck('attachment_key')->filter()->values()->all();

                if ($keys !== []) {
                    $this->disk()->delete($keys);
                }

                $deleted += ContactRequest::query()->whereIn('id', $requests->pluck('id'))->delete();
            });

        return $deleted;
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
