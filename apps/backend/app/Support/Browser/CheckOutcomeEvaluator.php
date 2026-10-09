<?php

namespace App\Support\Browser;

use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckStep;
use App\Models\Incident;
use App\Models\Signal;
use App\Support\Incidents\IncidentRecorder;
use Illuminate\Support\Carbon;

class CheckOutcomeEvaluator
{
    public const FAMILY = 'checkout';

    public const COMPONENT_PAYMENT_FORM = 'payment_form';

    public const COMPONENT_TEST_PRODUCT = 'test_product';

    public const COMPONENT_MONITORING_ACCESS = 'monitoring_access';

    public const COMPONENT_ADAPTER = 'adapter';

    public const TITLE_FLOW_FAILED = 'CHECKOUT_FLOW_FAILED';

    public const TITLE_TEST_PRODUCT_UNAVAILABLE = 'CHECKOUT_TEST_PRODUCT_UNAVAILABLE';

    public const TITLE_MONITORING_BLOCKED = 'CHECKOUT_MONITORING_BLOCKED';

    public const TITLE_ADAPTER_UNSUPPORTED = 'CHECKOUT_ADAPTER_UNSUPPORTED';

    public const RECOVERY_PASSES = 2;

    public const RECOVERY_MIN_SPACING_SECONDS = 300;

    public const RULE_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public function __construct(
        private readonly IncidentRecorder $incidents,
    ) {}

    public function runFinished(CheckRun $run, CheckAttempt $attempt): void
    {
        $problem = $this->problem($run);

        if ($problem !== null) {
            [$component, $title, $severity] = $problem;
            $this->incidents->openOrAttach($run->tenant_id, $run->store_id, $this->fingerprint($run, $component), [
                'family' => self::FAMILY,
                'component' => $component,
                'title_code' => $title,
                'severity' => $severity,
                'first_bad_at' => $run->started_at,
                'last_good_at' => $this->lastPassedAt($run),
            ], $this->signal($run, $attempt, $severity === Incident::SEVERITY_INFO ? Signal::SEVERITY_INFO : Signal::SEVERITY_WARNING), 'browser_check_'.$run->status);

            return;
        }

        if ($run->status !== CheckRun::STATUS_PASSED) {
            return;
        }

        foreach ([self::COMPONENT_TEST_PRODUCT, self::COMPONENT_MONITORING_ACCESS, self::COMPONENT_ADAPTER] as $coverage) {
            $this->resolveIfActive($run, $attempt, $coverage);
        }

        if ($this->recoveredByScheduledPasses($run)) {
            $this->resolveIfActive($run, $attempt, self::COMPONENT_PAYMENT_FORM);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function problem(CheckRun $run): ?array
    {
        return match (true) {
            $run->status === CheckRun::STATUS_FAILED && $run->error_code === CheckOutcomePolicy::SITE_FAILURE => [self::COMPONENT_PAYMENT_FORM, self::TITLE_FLOW_FAILED, Incident::SEVERITY_WARNING],
            $run->status === CheckRun::STATUS_FAILED && $run->error_code === CheckOutcomePolicy::PRODUCT_UNAVAILABLE => [self::COMPONENT_TEST_PRODUCT, self::TITLE_TEST_PRODUCT_UNAVAILABLE, Incident::SEVERITY_INFO],
            $run->status === CheckRun::STATUS_BLOCKED && in_array($run->error_code, [CheckOutcomePolicy::WAF_CHALLENGE, CheckOutcomePolicy::BLOCKED_EXTERNAL_ORIGIN], true) => [self::COMPONENT_MONITORING_ACCESS, self::TITLE_MONITORING_BLOCKED, Incident::SEVERITY_INFO],
            $run->status === CheckRun::STATUS_UNSUPPORTED,
            $run->status === CheckRun::STATUS_BLOCKED => [self::COMPONENT_ADAPTER, self::TITLE_ADAPTER_UNSUPPORTED, Incident::SEVERITY_INFO],
            default => null,
        };
    }

    private function recoveredByScheduledPasses(CheckRun $run): bool
    {
        $recent = CheckRun::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('scenario_id', $run->scenario_id)
            ->where('trigger', CheckRun::TRIGGER_SCHEDULED)
            ->whereIn('status', [CheckRun::STATUS_PASSED, CheckRun::STATUS_FAILED])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(self::RECOVERY_PASSES)
            ->get();

        if ($recent->count() < self::RECOVERY_PASSES || $recent->contains(fn (CheckRun $candidate): bool => $candidate->status !== CheckRun::STATUS_PASSED)) {
            return false;
        }

        return $recent->first()->finished_at->diffInSeconds($recent->last()->finished_at, true) >= self::RECOVERY_MIN_SPACING_SECONDS;
    }

    private function resolveIfActive(CheckRun $run, CheckAttempt $attempt, string $component): void
    {
        $fingerprint = $this->fingerprint($run, $component);

        if ($this->incidents->active($run->tenant_id, $run->store_id, $fingerprint) === null) {
            return;
        }

        $this->incidents->resolve(
            $run->tenant_id,
            $run->store_id,
            $fingerprint,
            $component === self::COMPONENT_PAYMENT_FORM ? 'auto_resolved_scheduled_checks_passed' : 'auto_resolved_check_passed',
            $this->signal($run, $attempt, Signal::SEVERITY_INFO),
            $run->finished_at,
        );
    }

    private function lastPassedAt(CheckRun $run): ?Carbon
    {
        $finishedAt = CheckRun::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('scenario_id', $run->scenario_id)
            ->where('status', CheckRun::STATUS_PASSED)
            ->where('id', '!=', $run->id)
            ->max('finished_at');

        return $finishedAt === null ? null : Carbon::parse($finishedAt);
    }

    private function signal(CheckRun $run, CheckAttempt $attempt, string $severity): Signal
    {
        $failedStep = CheckStep::query()
            ->where('attempt_id', $attempt->id)
            ->whereIn('status', ['failed', 'blocked', 'inconclusive'])
            ->orderBy('step_index')
            ->first();

        return Signal::query()->firstOrCreate(
            [
                'tenant_id' => $run->tenant_id,
                'dedupe_key' => 'browser_check:'.$run->id.':'.$run->status,
            ],
            [
                'store_id' => $run->store_id,
                'signal_type' => Signal::TYPE_BROWSER_CHECK,
                'family' => self::FAMILY,
                'component' => $run->status,
                'severity' => $severity,
                'confidence' => Signal::CONFIDENCE_OBSERVED,
                'rule_version' => self::RULE_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'check_run_id' => $run->id,
                'observed_start' => $run->started_at,
                'observed_end' => $run->finished_at,
                'evidence' => [
                    'check_run_id' => $run->id,
                    'trigger' => $run->trigger,
                    'run_status' => $run->status,
                    'error_code' => $run->error_code,
                    'failed_step' => $failedStep?->step_code,
                    'attempts' => CheckAttempt::query()->where('run_id', $run->id)->count(),
                    'location' => $attempt->location,
                ],
                'data_quality' => ['source' => 'synthetic_browser_check'],
                'detected_at' => Carbon::now(),
            ],
        );
    }

    private function fingerprint(CheckRun $run, string $component): string
    {
        return hash('sha256', implode('|', [self::FAMILY, $component, $run->scenario_id]));
    }
}
