<?php

namespace Tests\Feature\Platform;

use App\Support\Platform\PlatformAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformAuditPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_platform_audit_entries_older_than_the_retention_period_are_deleted(): void
    {
        $audit = app(PlatformAudit::class);

        foreach (['2024-10-17T10:00:00Z', '2024-10-19T10:00:00Z', '2026-10-01T10:00:00Z'] as $time) {
            Carbon::setTestNow($time);
            $audit->record(null, 'test.entry', 'test', $time, PlatformAudit::RESULT_SUCCEEDED);
        }

        Carbon::setTestNow('2026-10-18T12:00:00Z');
        $this->artisan('platform:audit-purge')->expectsOutput('deleted=1 before=2024-10-18')->assertSuccessful();
        $this->assertSame(['2024-10-19T10:00:00Z', '2026-10-01T10:00:00Z'], DB::table('platform_audit_log')->orderBy('created_at')->pluck('target_id')->all());

        config()->set('watchdog.platform.audit_retention_days', 30);
        $this->artisan('platform:audit-purge')->expectsOutput('deleted=1 before=2026-09-18')->assertSuccessful();
        $this->assertSame(['2026-10-01T10:00:00Z'], DB::table('platform_audit_log')->pluck('target_id')->all());
    }
}
