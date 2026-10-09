<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Exceptions\Notifications\NotificationChannelRejected;
use App\Http\Controllers\Controller;
use App\Http\Dto\Notifications\NotificationChannelDto;
use App\Http\Dto\Notifications\NotificationDeliveryDto;
use App\Http\Requests\Notifications\CreateNotificationChannelRequest;
use App\Http\Requests\Notifications\UpdateNotificationChannelRequest;
use App\Http\Requests\Notifications\VerifyNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoles;
use App\Support\Notifications\NotificationChannelService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    public function __construct(
        private readonly NotificationChannelDto $channelDto,
        private readonly NotificationDeliveryDto $deliveryDto,
        private readonly NotificationChannelService $service,
    ) {}

    public function index(TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'list notification channels');

        $channels = NotificationChannel::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $this->channelDto->collection($channels, $tenant)]);
    }

    public function store(CreateNotificationChannelRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'create notification channel');
        $validated = $request->validated();

        if ($validated['kind'] !== NotificationChannel::KIND_EMAIL) {
            return $this->rejected(new NotificationChannelRejected('channel_kind_not_supported_yet'));
        }

        if ($this->bypassRequestedByNonOwner($request, $validated, $tenant)) {
            return $this->rejected(new NotificationChannelRejected('critical_bypass_requires_owner'), 403);
        }

        try {
            $channel = $this->service->createEmailChannel(
                $tenant,
                $validated['label'],
                $validated['destination_email'],
                $validated['preferences'] ?? [],
                $request->user()?->id,
            );
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json(array_merge($this->channelDto->toArray($channel, $tenant), [
            'verification' => [
                'method' => 'email_code',
                'expires_in_seconds' => 15 * 60,
            ],
        ]), 201);
    }

    public function update(UpdateNotificationChannelRequest $request, NotificationChannel $notificationChannel, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'update notification channel');

        abort_unless($notificationChannel->tenant_id === $tenant->id, 404);

        $validated = $request->validated();

        if ($this->bypassRequestedByNonOwner($request, $validated, $tenant)) {
            return $this->rejected(new NotificationChannelRejected('critical_bypass_requires_owner'), 403);
        }

        try {
            $channel = $this->service->update($notificationChannel, $tenant, $validated, $request->user()?->id);
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->channelDto->toArray($channel, $tenant));
    }

    public function verify(VerifyNotificationChannelRequest $request, NotificationChannel $notificationChannel, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'verify notification channel');

        abort_unless($notificationChannel->tenant_id === $tenant->id, 404);

        try {
            $channel = $this->service->verify($notificationChannel, $request->validated()['code'], $request->user()?->id);
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->channelDto->toArray($channel, $tenant));
    }

    public function test(NotificationChannel $notificationChannel, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'test notification channel');

        abort_unless($notificationChannel->tenant_id === $tenant->id, 404);

        try {
            $delivery = $this->service->sendTest($notificationChannel, $tenant);
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->deliveryDto->toArray($delivery), 202);
    }

    private function tenant(TenantContext $tenantContext, string $operation): Tenant
    {
        /** @var Tenant */
        return Tenant::query()->whereKey($tenantContext->requireTenantId($operation))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function bypassRequestedByNonOwner(Request $request, array $validated, Tenant $tenant): bool
    {
        if (($validated['preferences']['critical_bypasses_quiet_hours'] ?? false) != true) {
            return false;
        }

        /** @var User|null $user */
        $user = $request->user();

        return $user === null || $user->memberships()->where('tenant_id', $tenant->id)->value('role') !== TenantRoles::OWNER;
    }

    private function rejected(NotificationChannelRejected $exception, ?int $status = null): JsonResponse
    {
        return response()->json([
            'code' => $exception->reasonCode,
            'message' => $exception->getMessage(),
        ], $status ?? $exception->httpStatus());
    }
}
