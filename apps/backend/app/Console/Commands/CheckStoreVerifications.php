<?php

namespace App\Console\Commands;

use App\Models\StoreVerification;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckStoreVerifications extends Command
{
    protected $signature = 'stores:check-verifications {--limit=50}';

    protected $description = 'Check pending store domain verifications (DNS TXT or connector challenge) and expire stale ones.';

    public function handle(StoreVerificationService $verifications): int
    {
        $now = Carbon::now();
        $expired = StoreVerification::query()
            ->where('status', StoreVerification::STATUS_PENDING)
            ->where('expires_at', '<=', $now)
            ->update(['status' => StoreVerification::STATUS_EXPIRED]);

        $due = StoreVerification::query()
            ->where('status', StoreVerification::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', $now->copy()->subSeconds((int) config('watchdog.store_verification.recheck_seconds'))))
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $verified = 0;

        foreach ($due as $verification) {
            if ($verifications->check($verification)->status === StoreVerification::STATUS_VERIFIED) {
                $verified++;
            }
        }

        $this->components->info(sprintf('Store verification check complete: checked=%d verified=%d expired=%d', $due->count(), $verified, $expired));

        return self::SUCCESS;
    }
}
