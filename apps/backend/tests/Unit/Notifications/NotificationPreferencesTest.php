<?php

namespace Tests\Unit\Notifications;

use App\Support\Notifications\MinorUnits;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class NotificationPreferencesTest extends TestCase
{
    public function test_defaults_come_from_the_tenant_and_warning_threshold(): void
    {
        $preferences = NotificationPreferences::fromArray([], 'de', 'Europe/Berlin');

        $this->assertSame('de', $preferences->locale);
        $this->assertSame('Europe/Berlin', $preferences->timezone);
        $this->assertSame('warning', $preferences->minSeverity);
        $this->assertTrue($preferences->notifyRecovery);
        $this->assertFalse($preferences->allowsSeverity('info'));
        $this->assertTrue($preferences->allowsSeverity('warning'));
        $this->assertTrue($preferences->allowsSeverity('critical'));
    }

    public function test_overnight_quiet_hours_defer_to_the_local_window_end(): void
    {
        $preferences = NotificationPreferences::fromArray([
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
        ], 'ru', 'UTC');

        $lateEvening = Carbon::parse('2026-10-09 23:30:00', 'Europe/Kyiv')->utc();
        $earlyMorning = Carbon::parse('2026-10-10 03:00:00', 'Europe/Kyiv')->utc();
        $daytime = Carbon::parse('2026-10-10 12:00:00', 'Europe/Kyiv')->utc();

        $this->assertTrue($preferences->nextAllowedAt($lateEvening, 'warning')->equalTo(Carbon::parse('2026-10-10 08:00:00', 'Europe/Kyiv')));
        $this->assertTrue($preferences->nextAllowedAt($earlyMorning, 'warning')->equalTo(Carbon::parse('2026-10-10 08:00:00', 'Europe/Kyiv')));
        $this->assertTrue($preferences->nextAllowedAt($daytime, 'warning')->equalTo($daytime));
    }

    public function test_quiet_hours_end_respects_dst_change(): void
    {
        $preferences = NotificationPreferences::fromArray([
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
        ], 'ru', 'UTC');

        $beforeDstEnd = Carbon::parse('2026-10-24 23:00:00', 'Europe/Kyiv')->utc();

        $this->assertSame(
            '2026-10-25 06:00:00',
            $preferences->nextAllowedAt($beforeDstEnd, 'warning')->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_critical_bypasses_quiet_hours_only_when_configured(): void
    {
        $night = Carbon::parse('2026-10-09 23:30:00', 'Europe/Kyiv')->utc();
        $default = NotificationPreferences::fromArray([
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
        ], 'ru', 'UTC');
        $bypass = NotificationPreferences::fromArray([
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
            'critical_bypasses_quiet_hours' => true,
        ], 'ru', 'UTC');

        $this->assertTrue($default->nextAllowedAt($night, 'critical')->greaterThan($night));
        $this->assertTrue($bypass->nextAllowedAt($night, 'critical')->equalTo($night));
        $this->assertTrue($bypass->nextAllowedAt($night, 'warning')->greaterThan($night));
    }

    public function test_minor_units_are_formatted_without_floats(): void
    {
        $this->assertSame('184.00', MinorUnits::format('18400', 2));
        $this->assertSame('0.05', MinorUnits::format('5', 2));
        $this->assertSame('0.00', MinorUnits::format('0', 2));
        $this->assertSame('1500', MinorUnits::format('1500', 0));
        $this->assertSame('1.234', MinorUnits::format('1234', 3));
        $this->assertSame('-12.34', MinorUnits::format('-1234', 2));
        $this->assertSame('92233720368547758.07', MinorUnits::format('9223372036854775807', 2));
    }
}
