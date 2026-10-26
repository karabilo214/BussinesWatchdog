<?php

namespace Tests\Feature\Mail;

use App\Mail\NotificationMessageMail;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Mail\MailPreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://watchdog.test');
        config()->set('watchdog.site.owner_url', 'https://watchdog.test/owner');
        config()->set('watchdog.provider.name', 'Example Provider');
        config()->set('watchdog.provider.address', "Musterstraße 1\n10115 Berlin");
    }

    public function test_every_letter_is_sent_as_html_and_text_in_the_shared_layout(): void
    {
        User::query()->create(['name' => 'Anna Müller', 'salutation' => 'ms', 'first_name' => 'Anna', 'last_name' => 'Müller', 'email' => 'anna@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);

        app(AccountMailer::class)->send('Anna@Example.test', 'de', 'password_reset', ['minutes' => 60, 'link' => 'https://watchdog.test/app/reset-password?token=abc']);

        $email = $this->sentEmail();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        $this->assertSame('Business Watchdog: Passwort zurücksetzen', $email->getSubject());
        $this->assertStringContainsString('<html lang="de"', $html);
        $this->assertStringContainsString('src="https://watchdog.test/mail-logo.png"', $html);
        $this->assertStringContainsString('Sehr geehrte Frau Müller,', $html);
        $this->assertMatchesRegularExpression('#<a class="bw-button" href="https://watchdog.test/app/reset-password\?token=abc"[^>]*>Neues Passwort festlegen</a>#', $html);
        $this->assertStringContainsString('Example Provider · Musterstraße 1, 10115 Berlin', $html);
        $this->assertStringContainsString('href="https://watchdog.test/de/contact"', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);

        $this->assertStringStartsWith("Sehr geehrte Frau Müller,\n\n", $text);
        $this->assertStringContainsString("\nhttps://watchdog.test/app/reset-password?token=abc\n", $text);
        $this->assertStringContainsString('Kundenbereich: https://watchdog.test/app/', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_a_recipient_without_an_account_gets_a_neutral_greeting(): void
    {
        app(AccountMailer::class)->send('new@example.test', 'ru', 'email_change_confirm', ['hours' => 24, 'link' => 'https://watchdog.test/app/confirm']);

        $text = (string) $this->sentEmail()->getTextBody();

        $this->assertStringStartsWith("Здравствуйте!\n\nЭтот адрес указан", $text);
        $this->assertSame(1, substr_count($text, 'Здравствуйте'));
    }

    public function test_the_footer_depends_on_who_receives_the_letter(): void
    {
        $customer = (new NotificationMessageMail('S', 'Body', 'en'))->layout();
        $notifications = (new NotificationMessageMail('S', 'Body', 'en', NotificationMessageMail::AUDIENCE_NOTIFICATIONS))->layout();
        $staff = (new NotificationMessageMail('S', 'Body', 'ru', NotificationMessageMail::AUDIENCE_STAFF))->layout();
        $visitor = (new NotificationMessageMail('S', 'Body', 'de', NotificationMessageMail::AUDIENCE_VISITOR))->layout();

        $this->assertSame(['https://watchdog.test/en', 'https://watchdog.test/app/', 'https://watchdog.test/en/contact'], array_column($customer['links'], 'url'));
        $this->assertNull($customer['settings']);
        $this->assertSame('https://watchdog.test/app/notifications', $notifications['settings']['url']);
        $this->assertSame(['https://watchdog.test/ru', 'https://watchdog.test/owner/'], array_column($staff['links'], 'url'));
        $this->assertSame(['https://watchdog.test/de', 'https://watchdog.test/de/contact'], array_column($visitor['links'], 'url'));
        $this->assertSame('en', (new NotificationMessageMail('S', 'Body', 'fr', 'unknown'))->layout()['mailLocale']);
    }

    public function test_the_provider_line_is_left_out_until_it_is_configured(): void
    {
        config()->set('watchdog.provider.name', '');
        config()->set('watchdog.provider.address', '');

        $html = (new NotificationMessageMail('S', 'Body', 'en'))->render();

        $this->assertStringNotContainsString('Example Provider', $html);
        $this->assertStringNotContainsString(' · </p>', $html);
    }

    public function test_every_template_with_a_link_has_a_button_label_and_layout_texts_exist_in_every_language(): void
    {
        $keys = fn (array $values, string $prefix = ''): array => $this->flatKeys($values, $prefix);
        $mail = $keys(require lang_path('en/mail.php'));

        foreach (NotificationMessageMail::LOCALES as $locale) {
            $this->assertSame($mail, $keys(require lang_path("{$locale}/mail.php")), $locale);
            $account = require lang_path("{$locale}/account.php");

            foreach (MailPreviews::accountTemplates() as $template) {
                $body = $account[$template]['body'];

                if (str_contains($body, "\n:link") && ! str_starts_with($template, 'reminder_')) {
                    $this->assertNotEmpty($account[$template]['action'] ?? null, "{$locale}: {$template}");
                }

                $this->assertDoesNotMatchRegularExpression('/^(Здравствуйте|Hello|Hallo)\b/u', in_array($template, ['reminder_subscription', 'reminder_payment', 'reminder_other'], true) ? '' : $body, "{$locale}: {$template} greets twice");
            }
        }
    }

    public function test_the_preview_renders_every_letter_in_every_language(): void
    {
        $letters = app(MailPreviews::class)->all();

        foreach (NotificationMessageMail::LOCALES as $locale) {
            foreach (MailPreviews::accountTemplates() as $template) {
                $this->assertArrayHasKey("account.{$template}.{$locale}", $letters);
            }

            foreach (['service_alert.problem', 'service_alert.recovered', 'notification.incident_opened', 'notification.incident_recovered', 'notification.daily_digest', 'notification.test', 'notification.verification', 'contact.reply'] as $name) {
                $this->assertArrayHasKey("{$name}.{$locale}", $letters);
            }
        }

        $this->assertArrayHasKey('contact.notify.ru', $letters);

        foreach ($letters as $name => $letter) {
            $html = $letter->render();
            $this->assertStringContainsString('class="bw-card"', $html, $name);
            $this->assertDoesNotMatchRegularExpression('/(?<![\w])(account|mail|notifications|site)\.[a-z_]+\.[a-z_.]+/', strip_tags($html), $name);
            $this->assertStringNotContainsString(':link', $html, $name);
        }

        $this->artisan('mail:preview', ['--to' => 'preview@example.test', '--only' => 'password_reset', '--locale' => ['de']])
            ->expectsOutput('letters=1 sent_to=preview@example.test')
            ->assertSuccessful();
    }

    public function test_no_letter_is_sent_around_the_layout(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPathname());
            $code = (string) file_get_contents($file->getPathname());

            if (preg_match('/extends\s+Mailable\b/', $code) === 1 && $path !== 'app/Mail/NotificationMessageMail.php') {
                $offenders[] = "{$path}: another Mailable";
            }

            if (preg_match('/(?<!\w)Mail::(?!to\()\w+|MailMessage|function\s+toMail\b|htmlString|new\s+Email\s*\(/', $code, $match) === 1) {
                $offenders[] = "{$path}: {$match[0]}";
            }

            preg_match_all('/Mail::to\((?:[^()]|\([^()]*\))*\)\s*->\s*(\w+)\(\s*([^\n]*)/', $code, $sends, PREG_SET_ORDER);

            foreach ($sends as $send) {
                if ($send[1] !== 'send' || preg_match('/^(new NotificationMessageMail\(|\$this->letter\(|\$letter\))/', $send[2]) !== 1) {
                    $offenders[] = "{$path}: {$send[0]}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    private function sentEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function flatKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $keys = [...$keys, ...(is_array($value) ? $this->flatKeys($value, "{$prefix}{$key}.") : ["{$prefix}{$key}"])];
        }

        sort($keys);

        return $keys;
    }
}
