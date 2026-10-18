<?php

namespace App\Support\Platform;

use App\Models\MaintenanceNotice;
use Illuminate\Support\Carbon;

/**
 * Our own maintenance (ADR 0054): while a window is active, scheduled browser checks, connector freshness, customer
 * notifications and the checks that would read our outage as the store's (stuck payment attempts, a silent checkout
 * script) pause. After it ends, a gap that started inside the window is measured from the end of the window, so it
 * does not become an incident at once. Billing is not paused.
 */
class MaintenanceWindow
{
    public function active(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return MaintenanceNotice::query()->whereNull('cancelled_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->exists();
    }

    /**
     * The end of the latest window that really ran (started and not cancelled before it started).
     */
    public function lastEndedAt(?Carbon $now = null): ?Carbon
    {
        $now ??= Carbon::now();
        $ended = MaintenanceNotice::query()
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('cancelled_at')->orWhereColumn('cancelled_at', '>', 'starts_at'))
            ->get(['ends_at', 'cancelled_at'])
            ->map(fn (MaintenanceNotice $notice): Carbon => $notice->cancelled_at !== null && $notice->cancelled_at->lessThan($notice->ends_at) ? $notice->cancelled_at : $notice->ends_at)
            ->filter(fn (Carbon $end): bool => $end->lessThanOrEqualTo($now))
            ->max();

        return $ended instanceof Carbon ? $ended->copy() : null;
    }

    /**
     * A moment to count a gap from: the later of the last sign of life and the end of the last window.
     */
    public function since(?Carbon $last, ?Carbon $now = null): ?Carbon
    {
        $ended = $this->lastEndedAt($now);

        return $ended !== null && ($last === null || $ended->greaterThan($last)) ? $ended : $last;
    }
}
