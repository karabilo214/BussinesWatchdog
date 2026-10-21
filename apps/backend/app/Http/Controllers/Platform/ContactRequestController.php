<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use App\Models\ContactRequestReply;
use App\Models\PlatformStaff;
use App\Support\Site\ContactInbox;
use App\Support\Site\SiteRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class ContactRequestController extends Controller
{
    public function __construct(private readonly ContactInbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ContactRequest::STATUSES)],
            'cursor' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($this->inbox->list($validated));
    }

    public function show(string $contactRequest): JsonResponse
    {
        try {
            return response()->json($this->inbox->dto($this->inbox->find($contactRequest)));
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The message was not found.'], $exception->status);
        }
    }

    /**
     * Always a download, never shown inline: the file came from a stranger, even after it was checked and cleaned.
     */
    public function attachment(Request $request, string $contactRequest): Response
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            $file = $this->inbox->attachment($staff->id, $contactRequest);
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The attachment was not found.'], $exception->status);
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['name'], trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($file['name'])), '_.') ?: 'attachment'),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function note(Request $request, string $contactRequest): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            $this->inbox->addNote($staff->id, $contactRequest, $validated['body']);

            return response()->json($this->inbox->dto($this->inbox->find($contactRequest)), 201);
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The note was not added.'], $exception->status);
        }
    }

    public function editNote(Request $request, string $contactRequest, string $note): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            $this->inbox->editNote($staff->id, $contactRequest, $note, $validated['body']);

            return response()->json($this->inbox->dto($this->inbox->find($contactRequest)));
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The note was not changed.'], $exception->status);
        }
    }

    public function saveTemplate(Request $request, string $code, string $locale): JsonResponse
    {
        $validated = $request->validate(['subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000']]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            return response()->json($this->inbox->saveReplyTemplate($staff->id, $code, $locale, $validated['subject'], $validated['body']));
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The template was not saved.'], $exception->status);
        }
    }

    public function reply(Request $request, string $contactRequest): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(ContactRequestReply::TEMPLATES)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'min:2', 'max:10000'],
        ]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            $this->inbox->reply($staff->id, $contactRequest, $validated);

            return response()->json($this->inbox->dto($this->inbox->find($contactRequest)), 201);
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The reply was not sent.'], $exception->status);
        }
    }

    public function update(Request $request, string $contactRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ContactRequest::STATUSES)],
        ]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            return response()->json($this->inbox->dto($this->inbox->update($staff->id, $contactRequest, $validated)));
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The message change was rejected.'], $exception->status);
        }
    }
}
