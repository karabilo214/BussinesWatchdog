<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'backend',
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
        ];

        $ready = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'service' => 'backend',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        return $this->safeCheck(function (): void {
            DB::select('select 1');
        });
    }

    private function checkRedis(): array
    {
        return $this->safeCheck(function (): void {
            Redis::connection()->ping();
        });
    }

    private function checkCache(): array
    {
        return $this->safeCheck(function (): void {
            Cache::put('health:ready', 'ok', 5);
            Cache::get('health:ready');
        });
    }

    private function safeCheck(callable $callback): array
    {
        try {
            $callback();

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
