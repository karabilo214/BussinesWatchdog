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
use Illuminate\Validation\Rule;

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
            'status' => ['sometimes', 'string', Rule::in(ContactRequest::STATUSES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
