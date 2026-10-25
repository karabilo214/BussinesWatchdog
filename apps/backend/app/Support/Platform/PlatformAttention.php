<?php

namespace App\Support\Platform;

use App\Models\BillingRefund;
use App\Models\ContactRequest;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Small, permission-filtered menu counters. No tenant identity or record payload leaves this endpoint. */
class PlatformAttention
{
    public function __construct(private readonly ServiceHealth $health) {}

    /** @return array<string, int|string> */
    public function forStaff(PlatformStaff $staff): array
    {
        $result = [];

        if (PlatformCapabilities::allows($staff, PlatformCapabilities::TENANTS_SUPPORT)) {
            $result['support_requests_pending'] = DB::table('support_access_grants')
                ->join('tenants', 'tenants.id', '=', 'support_access_grants.tenant_id')
                ->where('support_access_grants.staff_id', $staff->id)
                ->where('support_access_grants.state', SupportAccessGrant::PENDING)
                ->whereNull('support_access_grants.staff_hidden_at')
                ->whereNotIn('tenants.status', ['deleting', 'deleted'])->count();
        }

        if (PlatformCapabilities::allows($staff, PlatformCapabilities::BILLING_READ)) {
            $result['subscriptions_past_due'] = DB::table('subscriptions')
                ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
                ->where('subscriptions.status', Subscription::STATUS_PAST_DUE)
                ->where('tenants.status', '!=', 'deleted')->count();
        }

        if (PlatformCapabilities::allows($staff, PlatformCapabilities::REFUNDS_APPROVE)) {
            $result['refunds_requested'] = BillingRefund::query()
                ->where('mode', PaddleConfig::mode())
                ->where('status', BillingRefund::STATUS_REQUESTED)->count();
        }

        if (PlatformCapabilities::allows($staff, PlatformCapabilities::CONTACTS_READ)) {
            $result['contacts_new'] = ContactRequest::query()->where('status', ContactRequest::STATUS_NEW)->count();
        }

        if (PlatformCapabilities::allows($staff, PlatformCapabilities::SERVICE_READ)) {
            try {
                $result['service_state'] = Cache::remember('platform:menu:service-state:v1', 30, fn (): string => $this->health->build()['state']);
            } catch (Throwable) {
                // Cache failure must not turn an unknown service state into a healthy badge.
                $result['service_state'] = $this->health->build()['state'];
            }
        }

        return $result;
    }
}
