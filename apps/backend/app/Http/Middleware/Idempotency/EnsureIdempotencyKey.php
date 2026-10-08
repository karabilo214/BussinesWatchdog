<?php

namespace App\Http\Middleware\Idempotency;

use App\Models\IdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotencyKey
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        abort_if($key === null || trim($key) === '', 400, 'Idempotency-Key header is required.');

        $tenantId = $this->tenantContext->requireTenantId('idempotent request');
        $actorId = $request->user()?->id;
        $route = $request->route()?->getName() ?? ($request->method().' '.$request->path());
        $requestHash = hash('sha256', (string) $request->getContent());

        return DB::transaction(function () use ($request, $next, $tenantId, $actorId, $route, $key, $requestHash): Response {
            try {
                $reservation = IdempotencyKey::query()->create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actorId,
                    'route' => $route,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => null,
                    'response_body' => null,
                    'created_at' => now(),
                    'expires_at' => now()->addHours(IdempotencyKey::TTL_HOURS),
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->replayOrConflict($tenantId, $route, $key, $requestHash);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                $reservation->forceFill([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                ])->save();
            } else {
                $reservation->delete();
            }

            return $response;
        });
    }

    private function replayOrConflict(string $tenantId, string $route, string $key, string $requestHash): Response
    {
        $existing = IdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing === null || $existing->response_status === null) {
            abort(409, 'Idempotency-Key is currently being processed by another request.');
        }

        if ($existing->request_hash !== $requestHash) {
            return response()->json([
                'code' => 'idempotency_key_conflict',
                'message' => 'This Idempotency-Key was already used with a different request body.',
            ], 409);
        }

        return response($existing->response_body ?? '', $existing->response_status, [
            'Content-Type' => 'application/json',
        ]);
    }
}
