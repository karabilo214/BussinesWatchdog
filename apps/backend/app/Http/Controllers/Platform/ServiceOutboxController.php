<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\DomainOutbox;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceOutboxController extends Controller
{
    public function __construct(private readonly MfaService $mfa, private readonly PlatformAudit $audit) {}

    public function index(): JsonResponse
    {
        $messages = DomainOutbox::query()
            ->where('status', DomainOutbox::STATUS_DEAD_LETTER)
            ->orderBy('created_at')->orderBy('id')->limit(50)
            ->get(['id', 'topic', 'error_code', 'attempts', 'created_at']);

        return response()->json(['data' => $messages])->header('Cache-Control', 'no-store');
    }

    public function retry(Request $request, string $message): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);
        /** @var PlatformStaff $actor */
        $actor = $request->attributes->get('platform_staff');

        if (! $this->mfa->verify($actor->user, $validated['confirmation_code'])) {
            $this->audit->record($actor->id, 'service.outbox_retry', 'domain_outbox', $message, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        return DB::transaction(function () use ($actor, $message, $validated): JsonResponse {
            /** @var DomainOutbox|null $record */
            $record = DomainOutbox::query()->whereKey($message)->lockForUpdate()->first();

            if ($record === null) {
                $this->audit->record($actor->id, 'service.outbox_retry', 'domain_outbox', $message, PlatformAudit::RESULT_DENIED, ['stage' => 'missing'], trim($validated['reason']));

                return response()->json(['code' => 'not_found'], 404);
            }
            if ($record->status !== DomainOutbox::STATUS_DEAD_LETTER) {
                $this->audit->record($actor->id, 'service.outbox_retry', 'domain_outbox', $message, PlatformAudit::RESULT_DENIED, ['stage' => 'status', 'status' => $record->status], trim($validated['reason']), $record->tenant_id);

                return response()->json(['code' => 'outbox_not_dead'], 409);
            }

            $previousError = $record->error_code;
            $before = [
                'status' => $record->status,
                'attempts' => $record->attempts,
                'next_attempt_at' => $record->next_attempt_at?->toJSON(),
                'lease_until' => $record->lease_until?->toJSON(),
                'published_at' => $record->published_at?->toJSON(),
                'error_code' => $record->error_code,
            ];
            $record->forceFill([
                'status' => DomainOutbox::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'lease_until' => null,
                'published_at' => null,
                'error_code' => null,
            ])->save();
            $this->audit->record($actor->id, 'service.outbox_retry', 'domain_outbox', $record->id, PlatformAudit::RESULT_SUCCEEDED, ['topic' => $record->topic, 'previous_error_code' => $previousError, 'before' => $before, 'after' => [
                'status' => $record->status,
                'attempts' => $record->attempts,
                'next_attempt_at' => $record->next_attempt_at?->toJSON(),
                'lease_until' => $record->lease_until?->toJSON(),
                'published_at' => $record->published_at?->toJSON(),
                'error_code' => $record->error_code,
            ]], trim($validated['reason']), $record->tenant_id);

            return response()->json(['id' => $record->id, 'status' => $record->status])->header('Cache-Control', 'no-store');
        });
    }
}
