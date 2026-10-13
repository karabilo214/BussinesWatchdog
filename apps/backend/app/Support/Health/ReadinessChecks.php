<?php

namespace App\Support\Health;

use App\Support\Security\Keyring;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessChecks
{
    public function __construct(
        private readonly Keyring $keyring,
    ) {}

    /**
     * @return array<string, array{ok: bool}>
     */
    public function all(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'cache' => $this->cache(),
            'keyring' => $this->keyring->isReady() ? $this->ok() : $this->failed(),
        ];
    }

    /**
     * @return array{ok: bool}
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->ok();
        } catch (Throwable) {
            return $this->failed();
        }
    }

    /**
     * @return array{ok: bool}
     */
    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return $this->ok();
        } catch (Throwable) {
            return $this->failed();
        }
    }

    /**
     * @return array{ok: bool}
     */
    private function cache(): array
    {
        try {
            Cache::put('health:ready', 'ok', 5);
            Cache::get('health:ready');

            return $this->ok();
        } catch (Throwable) {
            return $this->failed();
        }
    }

    /**
     * @return array{ok: true}
     */
    private function ok(): array
    {
        return ['ok' => true];
    }

    /**
     * @return array{ok: false}
     */
    private function failed(): array
    {
        return ['ok' => false];
    }
}
