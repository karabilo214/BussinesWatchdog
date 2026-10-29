<?php

namespace Tests\Feature\Site;

use App\Mail\NotificationMessageMail;
use App\Models\ContactRequest;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21T10:00:00Z');
        config()->set('app.url', 'https://watchdog.test');
        config()->set('watchdog.site.contact_attachment_disk', 'artifacts');
        Storage::fake('artifacts');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_form_needs_a_fresh_captcha_answer_each_time(): void
    {
        $this->get('/ru/contact')->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="captcha_answer"', false)
            ->assertSee('/ru/contact/captcha', false)
            ->assertSee('accept=".jpg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"', false)
            ->assertSee('JPG, PNG, WEBP, PDF');

        $image = $this->get('/ru/contact/captcha')->assertOk();
        $this->assertContains($image->headers->get('Content-Type'), ['image/png', 'image/svg+xml']);
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));

        $this->post('/ru/contact', $this->message())->assertRedirect('/ru/contact')->assertSessionHasErrors('captcha_answer');
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $this->captcha('ru') + 1])->assertSessionHasErrors('captcha_answer')->assertSessionHasInput('name', 'Анна');

        $answer = $this->captcha('ru');
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $answer])->assertSessionHas('contact_sent', true);
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $answer])->assertSessionHasErrors('captcha_answer');

        $late = $this->captcha('ru');
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $late])->assertSessionHasErrors('captcha_answer');

        $this->assertSame(1, ContactRequest::query()->count());
        $this->assertNull(ContactRequest::query()->sole()->attachment_key);
    }

    public function test_text_and_image_share_one_expiring_challenge_and_do_not_expose_the_answer(): void
    {
        foreach (['ru', 'en', 'de'] as $locale) {
            $this->get('/'.$locale.'/contact')->assertOk()->assertSee('data-captcha-text=', false);
            $challenge = session('contact_math_captcha');
            $text = $this->get('/'.$locale.'/contact/captcha/text')->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
            [$left, $right] = explode(' + ', $challenge['question']);
            $text->assertContent(trans('site.forms.captcha_question', compact('left', 'right'), $locale));
            $this->assertStringContainsString('no-store', $text->headers->get('Cache-Control'));
            $this->get('/'.$locale.'/contact/captcha')->assertOk();
            $this->assertSame($challenge, session('contact_math_captcha'));
            $this->post('/'.$locale.'/contact', $this->message() + ['captcha_answer' => (int) $left + (int) $right])
                ->assertSessionHas('contact_sent', true);
            $this->post('/'.$locale.'/contact', $this->message() + ['captcha_answer' => (int) $left + (int) $right])
                ->assertSessionHasErrors('captcha_answer');
        }
    }

    public function test_an_image_is_cleaned_kept_privately_and_given_to_staff_only_as_a_download(): void
    {
        $png = $this->png()."<?php system(\$_GET['c']); ?>";
        $this->post('/en/contact', $this->message() + ['captcha_answer' => $this->captcha('en'), 'attachment' => UploadedFile::fake()->createWithContent('Screen shot/../"error".png', $png)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('contact_sent', true);

        $request = ContactRequest::query()->sole();
        $this->assertStringStartsWith('contact-attachments/', (string) $request->attachment_key);
        $this->assertStringEndsWith('.png', (string) $request->attachment_key);
        $this->assertSame(['_error_.png', 'image/png'], [$request->attachment_name, $request->attachment_content_type]);
        $stored = Storage::disk('artifacts')->get((string) $request->attachment_key);
        $this->assertStringStartsWith("\x89PNG", (string) $stored);
        $this->assertStringNotContainsString('<?php', (string) $stored);
        $this->assertSame(strlen((string) $stored), $request->attachment_bytes);

        $this->get("/platform-api/v1/contact-requests/{$request->id}/attachment")->assertUnauthorized();

        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson("/platform-api/v1/contact-requests/{$request->id}")->assertOk()
            ->assertJsonPath('attachment', ['name' => '_error_.png', 'content_type' => 'image/png', 'bytes' => $request->attachment_bytes]);
        $download = $this->get("/platform-api/v1/contact-requests/{$request->id}/attachment")->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame($stored, $download->getContent());
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'site.contact.attachment_downloaded')->where('target_id', $request->id)->count());

        $plain = ContactRequest::query()->create(['locale' => 'en', 'name' => 'Ben', 'email' => 'ben@shop.test', 'message' => 'No file here.', 'status' => 'new']);
        $this->getJson("/platform-api/v1/contact-requests/{$plain->id}")->assertOk()->assertJsonPath('attachment', null);
        $this->getJson("/platform-api/v1/contact-requests/{$plain->id}/attachment")->assertNotFound()->assertJsonPath('code', 'attachment_not_found');

        Mail::fake();
        $this->owner('owner@platform.test');
        $this->artisan('site:contact-notify')->assertSuccessful();
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => str_contains($mail->bodyText, 'Вложение: _error_.png ('));

        $request->forceFill(['status' => 'closed', 'closed_at' => Carbon::now()->subDays(400)])->save();
        $this->artisan('site:contact-purge')->expectsOutput('deleted=1')->assertSuccessful();
        Storage::disk('artifacts')->assertMissing((string) $request->attachment_key);
        $this->assertNotNull(ContactRequest::query()->find($plain->id));
    }

    public function test_a_pdf_is_kept_as_it_is(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $this->post('/de/contact', $this->message() + ['captcha_answer' => $this->captcha('de'), 'attachment' => UploadedFile::fake()->createWithContent('Rechnung.pdf', $pdf)])
            ->assertSessionHas('contact_sent', true);

        $request = ContactRequest::query()->sole();
        $this->assertSame(['Rechnung.pdf', 'application/pdf'], [$request->attachment_name, $request->attachment_content_type]);
        $this->assertSame($pdf, Storage::disk('artifacts')->get((string) $request->attachment_key));
    }

    public function test_unsafe_or_too_large_files_are_refused_and_nothing_is_stored(): void
    {
        $refused = [
            ['page.html', '<html><script>alert(1)</script></html>', 'не принимается'],
            ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'не принимается'],
            ['photo.png', "MZ\x90\x00 an executable renamed", 'не принимается'],
            ['shell.jpg', '<?php echo 1;', 'не принимается'],
            ['sheet.docx', "PK\x03\x04 a zip archive", 'не принимается'],
            ['broken.pdf', "%PDF-1.4\n no end", 'не удалось прочитать'],
        ];

        foreach ($refused as [$name, $content, $text]) {
            $this->post('/ru/contact', $this->message() + ['captcha_answer' => $this->captcha('ru'), 'attachment' => UploadedFile::fake()->createWithContent($name, $content)])
                ->assertRedirect('/ru/contact')
                ->assertSessionHasErrors('attachment')
                ->assertSessionHasInput('message');
            $this->assertStringContainsString($text, (string) session('errors')->first('attachment'), $name);
        }

        config()->set('watchdog.site.contact_attachment_max_bytes', 1024);
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $this->captcha('ru'), 'attachment' => UploadedFile::fake()->create('big.png', 2)])
            ->assertSessionHasErrors('attachment');
        $this->assertStringContainsString('больше', (string) session('errors')->first('attachment'));

        config()->set('watchdog.site.contact_attachment_types', []);
        $this->get('/ru/contact')->assertDontSee('name="attachment"', false);
        $this->post('/ru/contact', $this->message() + ['captcha_answer' => $this->captcha('ru'), 'attachment' => UploadedFile::fake()->createWithContent('a.png', $this->png())])
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, ContactRequest::query()->count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    /**
     * @return array<string, string>
     */
    private function message(): array
    {
        return ['name' => 'Анна', 'email' => 'anna@shop.test', 'message' => 'Проверка сайта не проходит, прикладываю скриншот.'];
    }

    private function captcha(string $locale): int
    {
        $this->get('/'.$locale.'/contact/captcha')->assertOk();
        preg_match('/^(\d+) \+ (\d+)$/', (string) (session('contact_math_captcha')['question'] ?? ''), $matches);

        return (int) $matches[1] + (int) $matches[2];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(8, 6);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function owner(string $email): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password_hash' => Hash::make('owner-password-1234'), 'locale' => 'ru']);
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
    }

    private function signIn(string $role): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
