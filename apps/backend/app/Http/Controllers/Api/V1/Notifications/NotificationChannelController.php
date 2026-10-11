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
use App\Models\NotificationChannelVerification;
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

        if ($this->bypassRequestedByNonOwner($request, $validated, $tenant)) {
            return $this->rejected(new NotificationChannelRejected('critical_bypass_requires_owner'), 403);
        }

        try {
            if ($validated['kind'] === NotificationChannel::KIND_TELEGRAM) {
                [$channel, $code] = $this->service->createTelegramChannel(
                    $tenant,
                    $validated['label'],
                    $validated['preferences'] ?? [],
                    $request->user()?->id,
                );

                return response()->json(array_merge($this->channelDto->toArray($channel, $tenant), [
                    'binding' => $this->telegramBindingPayload($code),
                ]), 201);
            }

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
                'expires_in_seconds' => NotificationChannelVerification::TTL_MINUTES * 60,
            ],
        ]), 201);
    }

    public function update(UpdateNotificationChannelRequest $request, NotificationChannel $notificationChannel, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'update notification channel');

        abort_unless($notificationChannel->tenant_id === $tenant->id, 404);

        $validated = $request->validated();

        if ($notificationChannel->kind === NotificationChannel::KIND_TELEGRAM && array_key_exists('destination_email', $validated)) {
            return $this->rejected(new NotificationChannelRejected('telegram_destination_is_bound_by_bot'));
        }

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

    public function resendVerification(Request $request, NotificationChannel $notificationChannel, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $this->tenant($tenantContext, 'resend notification channel verification');

        abort_unless($notificationChannel->tenant_id === $tenant->id, 404);

        try {
            [$channel, $code] = $this->service->resendVerification($notificationChannel, $tenant, $request->user()?->id);
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        if ($channel->kind === NotificationChannel::KIND_TELEGRAM) {
            return response()->json($this->telegramBindingPayload($code), 202);
        }

        return response()->json([
            'method' => 'email_code',
            'expires_in_seconds' => NotificationChannelVerification::TTL_MINUTES * 60,
        ], 202);
    }

    public function bindTelegram(Request $request): JsonResponse
    {
        if (! $this->telegramWebhookAuthorized($request)) {
            return response()->json(['code' => 'telegram_webhook_unauthorized', 'message' => 'telegram_webhook_unauthorized'], 401);
        }

        $validated = $request->validate([
            'binding_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'chat_id' => ['required', 'string', 'max:64'],
            'chat_title' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $channel = $this->service->bindTelegram($validated['binding_code'], $validated['chat_id'], $validated['chat_title'] ?? null);
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json([
            'channel_id' => $channel->id,
            'tenant_id' => $channel->tenant_id,
            'status' => 'bound',
        ]);
    }

    public function telegramUpdate(Request $request): JsonResponse
    {
        if (! $this->telegramWebhookAuthorized($request)) {
            return response()->json(['code' => 'telegram_webhook_unauthorized', 'message' => 'telegram_webhook_unauthorized'], 401);
        }

        $payload = $request->validate([
            'message.text' => ['sometimes', 'string', 'max:4096'],
            'message.chat.id' => ['sometimes'],
            'message.chat.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'message.chat.username' => ['sometimes', 'nullable', 'string', 'max:120'],
            'channel_post.text' => ['sometimes', 'string', 'max:4096'],
            'channel_post.chat.id' => ['sometimes'],
            'channel_post.chat.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'channel_post.chat.username' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $message = $payload['message'] ?? $payload['channel_post'] ?? null;
        $text = is_array($message) ? (string) ($message['text'] ?? '') : '';
        $chat = is_array($message) && isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];

        if (! preg_match('/\b(\d{6})\b/', $text, $matches) || ! array_key_exists('id', $chat)) {
            return response()->json(['status' => 'ignored'], 202);
        }

        try {
            $channel = $this->service->bindTelegram(
                $matches[1],
                (string) $chat['id'],
                $chat['title'] ?? $chat['username'] ?? null,
            );
        } catch (NotificationChannelRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json([
            'channel_id' => $channel->id,
            'tenant_id' => $channel->tenant_id,
            'status' => 'bound',
        ]);
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

    private function telegramWebhookAuthorized(Request $request): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $telegramHeader = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        $internalHeader = (string) $request->header('X-Business-Watchdog-Telegram-Secret');

        return hash_equals($secret, $telegramHeader) || hash_equals($secret, $internalHeader);
    }

    /**
     * @return array{method: string, binding_code: string, bot_username: string|null, expires_in_seconds: int}
     */
    private function telegramBindingPayload(string $code): array
    {
        return [
            'method' => 'telegram_code',
            'binding_code' => $code,
            'bot_username' => config('services.telegram.bot_username'),
            'expires_in_seconds' => NotificationChannelVerification::TTL_MINUTES * 60,
        ];
    }
}
