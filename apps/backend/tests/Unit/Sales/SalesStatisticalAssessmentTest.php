<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesCountTest;
use App\Support\Sales\SalesStatisticalAssessment;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SalesStatisticalAssessmentTest extends TestCase
{
    public function test_alpha_is_opt_in_and_does_not_test_unselected_windows(): void
    {
        $assessment = new SalesStatisticalAssessment(new SalesCountTest);
        $this->assertSame('not_requested', $assessment->report([], [], null)['result']);
        $result = $assessment->report([$this->window()], [], '0.01');
        $this->assertSame('abstain', $result['result']);
        $this->assertNull($result['test']);
        $this->assertSame('0.01', $result['alpha']['value']);
    }

    public function test_unknown_fact_or_insufficient_samples_never_become_a_count_test(): void
    {
        foreach (['coverage', 'actual', 'samples'] as $missing) {
            $window = $this->window();
            if ($missing === 'coverage') {
                $window['metric_coverage']['orders_created'] = 'unknown';
            } elseif ($missing === 'actual') {
                $window['orders_created'] = null;
            } else {
                $window['samples'] = array_slice($window['samples'], 0, 2);
            }
            $result = (new SalesStatisticalAssessment(new SalesCountTest))->report([$window], $this->selection(), '0.01');
            $this->assertContains('selected_window_not_comparable', $result['reason_codes']);
            $this->assertNull($result['test']);
        }
    }

    public function test_overlap_and_unequal_exposure_are_rejected_before_statistics(): void
    {
        foreach (['duplicate', 'exposure', 'clock_exposure', 'evaluation'] as $bad) {
            $window = $this->window();
            if ($bad === 'duplicate') {
                $window['samples'][1] = $window['samples'][0];
            } elseif ($bad === 'exposure') {
                $window['samples'][0]['duration_seconds'] = 7200;
            } elseif ($bad === 'clock_exposure') {
                $window['samples'][0]['to'] = '2026-08-02T13:00:00+00:00';
            } else {
                $window['samples'][0]['from'] = '2026-08-30T10:30:00+00:00';
                $window['samples'][0]['to'] = '2026-08-30T11:30:00+00:00';
            }
            $result = (new SalesStatisticalAssessment(new SalesCountTest))->report([$window], $this->selection(), '0.01');
            $this->assertContains('reference_windows_overlap_or_differ_in_exposure', $result['reason_codes']);
            $this->assertNull($result['test']);
        }
    }

    public function test_a_profile_point_estimate_is_not_silently_treated_as_poisson_evidence(): void
    {
        $window = $this->window();
        $window['warmup_profile']['candidates'][] = ['method' => 'weekday_daily_profile'];
        $result = (new SalesStatisticalAssessment(new SalesCountTest))->report([$window], $this->selection(), '0.01');
        $this->assertContains('profile_distribution_not_calibrated', $result['reason_codes']);
        $this->assertNull($result['test']);
        $this->assertFalse($result['alerts_enabled']);
    }

    private function window(): array
    {
        $samples = [];
        foreach ([2, 9, 16, 23] as $day) {
            $from = CarbonImmutable::parse(sprintf('2026-08-%02dT11:00:00Z', $day));
            $samples[] = ['from' => $from->toIso8601String(), 'to' => $from->addHour()->toIso8601String(), 'duration_seconds' => 3600, 'orders_created' => 20];
        }

        return [
            'width_hours' => 1, 'target_from' => '2026-08-30T11:00:00+00:00', 'target_to' => '2026-08-30T12:00:00+00:00', 'duration_seconds' => 3600,
            'orders_created' => 0, 'metric_coverage' => ['orders_created' => 'complete'], 'samples' => $samples,
            'warmup_profile' => ['structural_state' => 'available', 'required_same_weekday_samples' => 4, 'candidates' => [['method' => 'same_weekday_band']]],
        ];
    }

    private function selection(): array
    {
        return ['selections' => [['selected_window' => ['width_hours' => 1, 'target_from' => '2026-08-30T11:00:00+00:00', 'target_to' => '2026-08-30T12:00:00+00:00']]]];
    }
}
