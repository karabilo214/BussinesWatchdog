<?php

namespace App\Support\Scheduling;

use App\Models\ScheduledJobWindow;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ScheduledWindowGuard
{
    public const STALE_RUNNING_MINUTES = 120;

    public const ERROR_JOB_FAILED = 'scheduled_job_failed';

    /**
     * @template T of array<string, mixed>
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    public function runOnce(string $job, string $windowKey, Closure $callback): ?array
    {
        if (! $this->acquire($job, $windowKey)) {
            return null;
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->finish($job, $windowKey, ScheduledJobWindow::STATUS_FAILED, null, self::ERROR_JOB_FAILED);

            throw $exception;
        }

        $this->finish($job, $windowKey, ScheduledJobWindow::STATUS_COMPLETED, $result, null);

        return $result;
    }

    private function acquire(string $job, string $windowKey): bool
    {
        $now = Carbon::now();

        $inserted = ScheduledJobWindow::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'job' => $job,
            'window_key' => $windowKey,
            'status' => ScheduledJobWindow::STATUS_RUNNING,
            'started_at' => $now,
        ]);

        if ($inserted > 0) {
            return true;
        }

        $takenOver = ScheduledJobWindow::query()
            ->where('job', $job)
            ->where('window_key', $windowKey)
            ->where(fn ($query) => $query
                ->where('status', ScheduledJobWindow::STATUS_FAILED)
                ->orWhere(fn ($stale) => $stale
                    ->where('status', ScheduledJobWindow::STATUS_RUNNING)
                    ->where('started_at', '<=', $now->copy()->subMinutes(self::STALE_RUNNING_MINUTES))))
            ->update([
                'status' => ScheduledJobWindow::STATUS_RUNNING,
                'started_at' => $now,
                'finished_at' => null,
                'error_code' => null,
                'result' => null,
            ]);

        return $takenOver > 0;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(string $job, string $windowKey, string $status, ?array $result, ?string $errorCode): void
    {
        ScheduledJobWindow::query()
            ->where('job', $job)
            ->where('window_key', $windowKey)
            ->update([
                'status' => $status,
                'finished_at' => Carbon::now(),
                'error_code' => $errorCode,
                'result' => $result === null ? null : json_encode($result),
            ]);
    }
}
