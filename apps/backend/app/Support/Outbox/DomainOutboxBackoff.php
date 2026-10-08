<?php

namespace App\Support\Outbox;

class DomainOutboxBackoff
{
    public function retryDelaySeconds(int $attempts, int $baseSeconds = 30, int $maxSeconds = 3600): int
    {
        $attempts = max(1, $attempts);
        $baseSeconds = max(1, $baseSeconds);
        $maxSeconds = max($baseSeconds, $maxSeconds);
        $exponent = min($attempts - 1, 10);
        $delay = min($baseSeconds * (2 ** $exponent), $maxSeconds);
        $jitter = (int) floor($delay * 0.2);

        if ($jitter < 1) {
            return (int) $delay;
        }

        return max(1, (int) $delay + random_int(-$jitter, $jitter));
    }
}
