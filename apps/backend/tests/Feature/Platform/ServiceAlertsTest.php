<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Support\Health\ReadinessChecks;
use App\Support\Platform\QueueWorkerHeartbeats;
use App\Support\Platform\ServiceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ServiceAlertsTest extends TestCase
{
    use RefreshDatabase;

    private string $state = 'ok';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config()->set('watchdog.service_alerts.email', 'oncall@watchdog.test, second@watchdog.test, not-an-address');
        config()->set('watchdog.service_alerts.locale', 'ru');
        config()->set('watchdog.site.owner_url', 'https://watchdog.test/owner');
        $test = $this;
        $this->app->instance(ServiceHealth::class, new class(app(ReadinessChecks::class), $test) extends ServiceHealth
        {
            public function __construct(ReadinessChecks $readiness, private readonly ServiceAlertsTest $test)
            {
                parent::__construct($readiness, app(QueueWorkerHeartbeats::class));
            }

            public function build(?Carbon $now = null): array
            {
                $state = $this->test->state();
                $queue = fn (string $value): array => ['waiting' => 0, 'oldest_age_seconds' => null, 'problems' => 0, 'state' => $value];

                return [
                    'state' => $state,
                    'components' => ['database' => 'ok', 'redis' => $state === 'crit' ? 'crit' : 'ok', 'cache' => 'ok', 'keyring' => 'ok'],
                    'queues' => ['event_inbox' => $queue($state === 'crit' ? 'crit' : $state), 'jobs' => $queue('ok')],
                    'browser_checks' => ['state' => 'ok'],
                    'connector_sync' => ['state' => 'ok'],
                ];
            }
        });
    }

    public function state(): string
    {
        return $this->state;
    }

    public function test_the_responsible_person_hears_of_a_problem_once_and_of_the_recovery_once(): void
    {
        $this->artisan('service:alerts')->expectsOutput('state=ok sent=none')->assertSuccessful();
        Mail::assertNothingSent();

        $this->state = 'crit';
        $this->artisan('service:alerts')->expectsOutput('state=crit sent=problem');
        Mail::assertSent(NotificationMessageMail::class, 2);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('oncall@watchdog.test')
            && str_contains($mail->subjectLine, 'проблема в работе сервиса')
            && str_contains($mail->bodyText, '— Redis')
            && str_contains($mail->bodyText, '— приём событий магазинов')
            && str_contains($mail->bodyText, 'https://watchdog.test/owner/service'));

        $this->artisan('service:alerts')->expectsOutput('state=crit sent=none');
        $this->state = 'warn';
        $this->artisan('service:alerts')->expectsOutput('state=warn sent=recovered');
        $this->artisan('service:alerts')->expectsOutput('state=warn sent=none');
        $this->state = 'ok';
        $this->artisan('service:alerts')->expectsOutput('state=ok sent=none');
        Mail::assertSent(NotificationMessageMail::class, 4);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => str_contains($mail->subjectLine, 'снова в порядке'));
        $this->assertSame(['problem', 'recovered'], DB::table('platform_audit_log')->where('action', 'service.alert_sent')->orderBy('created_at')->pluck('target_id')->all());
    }

    public function test_a_failed_email_is_tried_again_and_nothing_is_sent_without_an_address(): void
    {
        $this->state = 'crit';
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
        $this->artisan('service:alerts')->expectsOutput('state=crit sent=none');
        $this->assertNull(DB::table('service_alert_state')->value('notified_state'));

        config()->set('watchdog.service_alerts.email', '');
        $this->artisan('service:alerts')->expectsOutput('state=crit sent=none');
        $this->assertNull(DB::table('service_alert_state')->value('notified_state'));
    }
}
