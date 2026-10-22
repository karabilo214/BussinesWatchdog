<?php

namespace App\Support\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Signs of life of the queue workers (`queue:work`) for «Сервис»: a queue backend that answers says nothing about
 * workers taking jobs. Every worker writes its row at most every 30 seconds while it waits, when it starts a job and
 * when it finishes one. A worker counts as alive when it waited recently or is inside a job younger than the longest
 * job timeout. A failed write never disturbs the worker.
 */
class QueueWorkerHeartbeats
{
    public const ALIVE_SECONDS = 120;

    public const BUSY_SECONDS = 960;

    private const WRITE_EVERY_SECONDS = 30;

    private static ?int $lastWrite = null;

    public function waiting(string $connection, ?string $queue): void
    {
        $now = Carbon::now();

        if (self::$lastWrite !== null && $now->getTimestamp() - self::$lastWrite < self::WRITE_EVERY_SECONDS) {
            return;
        }

        $this->write($connection, (string) $queue, ['seen_at' => $now, 'busy_since' => null]);
        $this->attempt(fn () => DB::table('queue_worker_heartbeats')->where('seen_at', '<', $now->copy()->subDay())->delete());
    }

    public function started(string $connection, string $queue): void
    {
        $now = Carbon::now();
        $this->write($connection, $queue, ['seen_at' => $now, 'busy_since' => $now]);
    }

    public function finished(string $connection, string $queue): void
    {
        $now = Carbon::now();
        $this->write($connection, $queue, ['seen_at' => $now, 'busy_since' => null, 'last_job_at' => $now], processed: true);
    }

    /**
     * @param  list<string>  $queues
     * @return array{alive: int, last_job_at: ?string}
     */
    public function summary(string $connection, array $queues, Carbon $now): array
    {
        $rows = DB::table('queue_worker_heartbeats')->where('connection', $connection)->where(function ($query) use ($queues): void {
            foreach ($queues as $queue) {
                $query->orWhere('queue', $queue)->orWhere('queue', 'like', $queue.',%')->orWhere('queue', 'like', '%,'.$queue)->orWhere('queue', 'like', '%,'.$queue.',%');
            }
        });
        $alive = (clone $rows)->where(fn ($query) => $query
            ->where('seen_at', '>=', $now->copy()->subSeconds(self::ALIVE_SECONDS))
            ->orWhere('busy_since', '>=', $now->copy()->subSeconds(self::BUSY_SECONDS)))
            ->count();
        $last = (clone $rows)->max('last_job_at');

        return ['alive' => $alive, 'last_job_at' => $last === null ? null : Carbon::parse($last)->toJSON()];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(string $connection, string $queue, array $values, bool $processed = false): void
    {
        self::$lastWrite = Carbon::now()->getTimestamp();
        $worker = gethostname().':'.getmypid();

        $this->attempt(function () use ($worker, $connection, $queue, $values, $processed): void {
            $updated = DB::table('queue_worker_heartbeats')->where('worker', $worker)->update($values + ['connection' => $connection, 'queue' => $queue]
                + ($processed ? ['processed' => DB::raw('processed + 1')] : []));

            if ($updated === 0) {
                DB::table('queue_worker_heartbeats')->insert($values + ['worker' => $worker, 'connection' => $connection, 'queue' => $queue, 'processed' => $processed ? 1 : 0]);
            }
        });
    }

    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
