<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Support\Account\AccountMailer;
use App\Support\Account\MfaService;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Platform\AuditJournal;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\ReminderRejected;
use App\Support\Platform\TenantActions;
use App\Support\Platform\TenantDirectory;
use App\Support\Platform\TenantReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantDirectory $directory,
        private readonly TenantActions $actions,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
        private readonly AuditJournal $journal,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tenant_status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'subscription' => ['sometimes', Rule::in(TenantDirectory::SUBSCRIPTION_FILTERS)],
            'plan' => ['sometimes', 'string', 'max:64'],
            'cancel_scheduled' => ['sometimes', 'boolean'],
            'trial_ends_before' => ['sometimes', 'date'],
            'cursor' => ['sometimes', 'uuid'],
        ]);
        $staff = $this->staff($request);

        return response()->json($this->directory->tenants($validated, $this->can($staff, PlatformCapabilities::BILLING_READ), $staff->role === PlatformStaff::ROLE_OWNER));
    }

    public function show(Request $request, string $tenant): JsonResponse
    {
        $staff = $this->staff($request);
        $card = $this->directory->tenant($tenant, $this->can($staff, PlatformCapabilities::BILLING_READ), $staff->role === PlatformStaff::ROLE_OWNER);

        return $card === null ? response()->json(['code' => 'not_found', 'message' => 'Organisation not found.'], 404) : response()->json($card);
    }

    public function audit(Request $request, string $tenant): JsonResponse
    {
        $validated = $request->validate(['cursor' => ['sometimes', 'uuid']]);
        if (! Str::isUuid($tenant) || ! Tenant::query()->whereKey($tenant)->whereNot('status', 'deleted')->exists()) {
            return response()->json(['code' => 'not_found', 'message' => 'Organisation not found.'], 404);
        }

        $staff = $this->staff($request);

        return response()->json($this->journal->tenantPage($tenant, $validated['cursor'] ?? null, $staff, $staff->user?->locale ?? 'en'));
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(array_values(array_diff(TenantDirectory::SUBSCRIPTION_FILTERS, ['none'])))],
            'plan' => ['sometimes', 'string', 'max:64'],
            'cancel_scheduled' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'uuid'],
        ]);

        return response()->json($this->directory->subscriptions($validated));
    }

    public function preview(Request $request, string $tenant, string $action): JsonResponse
    {
        $model = $this->tenantOr404($tenant);

        if ($model === null || ! in_array($action, TenantActions::ACTIONS, true)) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        try {
            return response()->json($this->actions->preview($model, $action, $this->input($request)));
        } catch (BillingCheckoutRejected $exception) {
            return $this->rejected($exception);
        }
    }

    public function act(Request $request, string $tenant, string $action): JsonResponse
    {
        $model = $this->tenantOr404($tenant);

        if ($model === null || ! in_array($action, TenantActions::ACTIONS, true)) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmation_code' => ['required', 'string', 'max:32']]);
        $staff = $this->staff($request);
        $input = $this->input($request);
        $auditAction = 'tenant.'.$action;

        if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
            $this->audit->record($staff->id, $auditAction, 'tenant', $model->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up'], null, $model->id);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        $before = $this->directory->access($model->id);
        $previousStatus = $model->status;
        $beforeFields = ['status' => $previousStatus];
        if ($action === TenantActions::EXTEND_TRIAL) {
            $previousTrialEnd = DB::table('subscriptions')->where('tenant_id', $model->id)->value('trial_ends_at');
            $beforeFields['trial_ends_at'] = $previousTrialEnd === null ? null : Carbon::parse($previousTrialEnd)->toJSON();
        }
        if ($action === TenantActions::REVOKE_OVERRIDE && Str::isUuid((string) ($input['override_id'] ?? ''))) {
            $beforeFields['revoked_at'] = null;
        }

        try {
            $this->actions->apply($model, $action, $input, $staff->id, trim($validated['reason']));
        } catch (BillingCheckoutRejected $exception) {
            $this->audit->record($staff->id, $auditAction, 'tenant', $model->id, PlatformAudit::RESULT_FAILED, ['code' => $exception->reasonCode] + $input, trim($validated['reason']), $model->id);

            return $this->rejected($exception);
        }

        $after = $this->directory->access($model->id);
        $afterFields = ['status' => $model->fresh()->status];
        if ($action === TenantActions::EXTEND_TRIAL) {
            $newTrialEnd = DB::table('subscriptions')->where('tenant_id', $model->id)->value('trial_ends_at');
            $afterFields['trial_ends_at'] = $newTrialEnd === null ? null : Carbon::parse($newTrialEnd)->toJSON();
        }
        if ($action === TenantActions::REVOKE_OVERRIDE && Str::isUuid((string) ($input['override_id'] ?? ''))) {
            $revokedAt = DB::table('entitlement_overrides')->where('tenant_id', $model->id)->where('id', $input['override_id'])->value('revoked_at');
            $afterFields['revoked_at'] = $revokedAt === null ? null : Carbon::parse($revokedAt)->toJSON();
        }
        $this->audit->record($staff->id, $auditAction, 'tenant', $model->id, PlatformAudit::RESULT_SUCCEEDED, $input + ['access_before' => $before['state'], 'access_after' => $after['state'], 'before' => $beforeFields, 'after' => $afterFields], trim($validated['reason']), $model->id);

        return response()->json($this->directory->tenant($model->id, true, $staff->role === PlatformStaff::ROLE_OWNER));
    }

    public function reminderPreview(Request $request, string $tenant, TenantReminders $reminders): JsonResponse
    {
        $model = $this->tenantOr404($tenant);

        if ($model === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $validated = $request->validate($this->reminderRules(false));

        return response()->json($reminders->preview($model, $validated['topic'], $validated['recipient_ids'] ?? null, $validated['letters'] ?? []));
    }

    public function remind(Request $request, string $tenant, TenantReminders $reminders): JsonResponse
    {
        $model = $this->tenantOr404($tenant);

        if ($model === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $validated = $request->validate($this->reminderRules(true));

        try {
            $sent = $reminders->send($model, $validated['topic'], $validated['recipient_ids'], $validated['letters'], $this->staff($request)->id);
        } catch (ReminderRejected $exception) {
            return $this->reminderRejected($exception);
        }

        return response()->json($sent, 201);
    }

    public function reminders(string $tenant, TenantReminders $reminders): JsonResponse
    {
        $model = $this->tenantOr404($tenant);

        if ($model === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        return response()->json(['data' => $reminders->history($model)]);
    }

    public function saveReminderTemplate(Request $request, string $topic, string $locale, TenantReminders $reminders): JsonResponse
    {
        abort_unless(in_array($topic, TenantReminders::TOPICS, true) && in_array($locale, AccountMailer::LOCALES, true), 404);
        $validated = $request->validate(['subject' => ['required', 'string', 'max:'.TenantReminders::SUBJECT_MAX], 'body' => ['required', 'string', 'max:'.TenantReminders::BODY_MAX]]);

        try {
            return response()->json($reminders->saveTemplate($this->staff($request)->id, $topic, $locale, $validated));
        } catch (ReminderRejected $exception) {
            return $this->reminderRejected($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reminderRules(bool $sending): array
    {
        return [
            'topic' => ['required', 'string', Rule::in(TenantReminders::TOPICS)],
            'recipient_ids' => [$sending ? 'required' : 'nullable', 'array', 'max:200'],
            'recipient_ids.*' => ['string', 'uuid'],
            'letters' => [$sending ? 'required' : 'nullable', 'array:'.implode(',', AccountMailer::LOCALES)],
            'letters.*.subject' => ['nullable', 'string', 'max:'.TenantReminders::SUBJECT_MAX],
            'letters.*.body' => ['nullable', 'string', 'max:'.TenantReminders::BODY_MAX],
        ];
    }

    private function reminderRejected(ReminderRejected $exception): JsonResponse
    {
        return response()->json(array_filter(['code' => $exception->reasonCode, 'message' => 'The reminder was not sent.', 'details' => $exception->locale === null ? null : ['locale' => $exception->locale]]), $exception->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_filter($request->only(['plan_version_id', 'extra_stores', 'ends_at', 'override_id', 'trial_ends_at']), fn (mixed $value): bool => $value !== null && (is_scalar($value)));
    }

    private function tenantOr404(string $tenant): ?Tenant
    {
        return Str::isUuid($tenant) ? Tenant::query()->whereKey($tenant)->whereNotIn('status', ['deleting', 'deleted'])->first() : null;
    }

    private function rejected(BillingCheckoutRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The action cannot be applied.'], $exception->status());
    }

    private function staff(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }

    private function can(PlatformStaff $staff, string $capability): bool
    {
        return PlatformCapabilities::allows($staff, $capability);
    }
}
