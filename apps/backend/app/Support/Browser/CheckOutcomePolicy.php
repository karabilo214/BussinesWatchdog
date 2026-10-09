<?php

namespace App\Support\Browser;

use App\Models\CheckAttempt;
use App\Models\CheckRun;

class CheckOutcomePolicy
{
    public const SITE_FAILURE = 'site_failure';

    public const PRODUCT_UNAVAILABLE = 'product_unavailable';

    public const WAF_CHALLENGE = 'waf_challenge';

    public const BLOCKED_EXTERNAL_ORIGIN = 'blocked_external_origin';

    public const FORBIDDEN_MUTATION = 'forbidden_mutation';

    public const SELECTOR_CHANGED = 'selector_changed';

    public const ADAPTER_UNSUPPORTED = 'adapter_unsupported';

    /**
     * Error codes a worker may report. Only site_failure can confirm a broken checkout;
     * the rest describe the test setup, access to the site or the worker itself.
     */
    public const ERROR_CODES = [
        self::SITE_FAILURE,
        self::PRODUCT_UNAVAILABLE,
        'worker_capacity',
        'dns_failure',
        self::WAF_CHALLENGE,
        self::SELECTOR_CHANGED,
        self::ADAPTER_UNSUPPORTED,
        self::FORBIDDEN_MUTATION,
        self::BLOCKED_EXTERNAL_ORIGIN,
        'infra_timeout',
    ];

    public const CONFIRMING_FAILURES = 2;

    /**
     * @return array{final: bool, run_status: string, error_code: ?string}
     */
    public function decide(CheckRun $run, CheckAttempt $attempt): array
    {
        $status = $attempt->status;
        $code = $attempt->error_code;

        if ($status === CheckRun::STATUS_PASSED || $status === CheckRun::STATUS_CANCELLED
            || $status === CheckRun::STATUS_BLOCKED || $status === CheckRun::STATUS_UNSUPPORTED) {
            return $this->final($status, $code);
        }

        if ($status === CheckRun::STATUS_FAILED && $code === self::PRODUCT_UNAVAILABLE) {
            return $this->final(CheckRun::STATUS_FAILED, $code);
        }

        if ($status === CheckRun::STATUS_FAILED && $code === self::SITE_FAILURE) {
            $siteFailures = CheckAttempt::query()
                ->where('run_id', $run->id)
                ->where('status', CheckRun::STATUS_FAILED)
                ->where('error_code', self::SITE_FAILURE)
                ->count();

            if ($siteFailures >= self::CONFIRMING_FAILURES) {
                return $this->final(CheckRun::STATUS_FAILED, $code);
            }

            return $this->retryOr(CheckRun::STATUS_INCONCLUSIVE, $run, $code);
        }

        return $this->retryOr(CheckRun::STATUS_INCONCLUSIVE, $run, $code ?? 'infra_timeout');
    }

    /**
     * @return array{final: bool, run_status: string, error_code: ?string}
     */
    private function retryOr(string $finalStatus, CheckRun $run, ?string $code): array
    {
        $attempts = CheckAttempt::query()->where('run_id', $run->id)->count();

        if ($attempts < (int) config('watchdog.browser.max_attempts')) {
            return ['final' => false, 'run_status' => CheckRun::STATUS_QUEUED, 'error_code' => $code];
        }

        return $this->final($finalStatus, $code);
    }

    /**
     * @return array{final: bool, run_status: string, error_code: ?string}
     */
    private function final(string $status, ?string $code): array
    {
        return ['final' => true, 'run_status' => $status, 'error_code' => $code];
    }
}
