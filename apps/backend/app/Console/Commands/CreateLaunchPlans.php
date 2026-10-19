<?php

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Billing\PlanAdmin;
use App\Support\Billing\PlanAdminRejected;
use Illuminate\Console\Command;

/**
 * Creates the launch plans of ADR 0066 as drafts with their monthly EUR prices, saved as the platform owner. Nothing
 * is sent to Paddle: the owner checks each draft in /owner/plans and publishes it, which creates it in Paddle.
 * Plans whose code already exists are left as they are.
 */
class CreateLaunchPlans extends Command
{
    protected $signature = 'billing:launch-plans {staff : Email of the platform owner the drafts are saved as}';

    protected $description = 'Create the launch plans (ADR 0066) as drafts in the owner panel';

    public function handle(PlanAdmin $plans): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('staff'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->first();

        if ($staff === null) {
            $this->error('No active platform owner with this email.');

            return self::FAILURE;
        }

        foreach (require database_path('data/launch_plans.php') as $code => $plan) {
            if (BillingPlan::query()->where('code', $code)->exists()) {
                $this->line("{$code}: exists, left as it is");

                continue;
            }

            try {
                $version = $plans->createPlan($staff->id, $code, $plan['name']);
                $plans->updateDraft($staff->id, $version->id, [
                    'description' => $plan['description'],
                    'trial_days' => $plan['trial_days'],
                    'order_volume_grace_days' => $plan['grace_days'],
                    'contact_sales' => $plan['contact_sales'] ?? false,
                    'faster_check_interval_minutes' => null,
                    'entitlements' => ['features' => $plan['features'], 'limits' => $plan['limits']],
                ]);

                foreach ($plan['prices'] as $kind => $amountMinor) {
                    $plans->addPrice($staff->id, $version->id, $kind, 'EUR', $amountMinor, 'month', 1);
                }
            } catch (PlanAdminRejected $rejected) {
                $this->error("{$code}: {$rejected->getMessage()}");

                return self::FAILURE;
            }

            $this->line("{$code}: draft created");
        }

        $this->info('Check the drafts in /owner/plans and publish each one.');

        return self::SUCCESS;
    }
}
