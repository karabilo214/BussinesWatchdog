<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\SiteMedia;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SiteMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_uploaded_photo_is_encoded_again_without_metadata_and_served_by_the_site(): void
    {
        $this->signIn();
        $jpeg = $this->jpegWithMetadata(40, 20);

        $media = $this->upload(UploadedFile::fake()->createWithContent("Urlaub\x07 2026.jpg", $jpeg))
            ->assertCreated()
            ->assertJsonPath('content_type', 'image/jpeg')
            ->assertJsonPath('name', 'Urlaub 2026.jpg')
            ->assertJsonPath('width', 20)
            ->assertJsonPath('height', 40)
            ->json();

        $this->assertMatchesRegularExpression('#^/media/[0-9a-f-]{36}\.jpg$#', $media['url']);
        $stored = Storage::disk('artifacts')->get(SiteMedia::query()->findOrFail($media['id'])->object_key);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('camera-serial-4711', $stored);

        $response = $this->get($media['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($stored, $response->getContent());
        $this->get(str_replace('.jpg', '.png', $media['url']))->assertNotFound();

        $this->assertSame([$media['id']], array_column($this->getJson('/platform-api/v1/site-media')->assertOk()->json('data'), 'id'));
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'site.media.uploaded', 'target_id' => $media['id']]);
    }

    public function test_only_jpeg_png_and_webp_within_the_limits_are_taken(): void
    {
        $this->signIn();

        $this->upload(UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'))->assertStatus(422)->assertJsonPath('code', 'media_type_unsupported');
        $this->upload(UploadedFile::fake()->createWithContent('anim.gif', $this->gd(4, 4, 'imagegif')))->assertStatus(422)->assertJsonPath('code', 'media_type_unsupported');
        $this->upload(UploadedFile::fake()->createWithContent('alpha.png', $this->gd(6, 4, 'imagepng')))->assertCreated()->assertJsonPath('content_type', 'image/png');
        $this->upload(UploadedFile::fake()->createWithContent('photo.webp', $this->gd(6, 4, 'imagewebp')))->assertCreated()->assertJsonPath('content_type', 'image/webp');

        config()->set('watchdog.site.media_max_side', 5);
        $this->upload(UploadedFile::fake()->createWithContent('wide.png', $this->gd(6, 4, 'imagepng')))->assertStatus(422)->assertJsonPath('code', 'media_dimensions_too_large');

        config()->set('watchdog.site.media_max_bytes', 10);
        $this->upload(UploadedFile::fake()->createWithContent('big.png', $this->gd(4, 4, 'imagepng')))->assertStatus(422)->assertJsonPath('code', 'media_too_large');

        $this->assertSame(2, SiteMedia::query()->count());
    }

    public function test_large_images_are_made_smaller_with_the_configured_side_and_quality(): void
    {
        $this->signIn();
        config()->set('watchdog.site.media_resize_max_side', 10);
        config()->set('watchdog.site.media_png_compression', 6);

        $this->upload(UploadedFile::fake()->createWithContent('wide.png', $this->gd(40, 20, 'imagepng')))->assertCreated()->assertJsonPath('width', 10)->assertJsonPath('height', 5);
        $this->upload(UploadedFile::fake()->createWithContent('small.png', $this->gd(8, 4, 'imagepng')))->assertCreated()->assertJsonPath('width', 8);

        config()->set('watchdog.site.media_resize_max_side', 0);
        $this->upload(UploadedFile::fake()->createWithContent('kept.png', $this->gd(40, 20, 'imagepng')))->assertCreated()->assertJsonPath('width', 40);
    }

    public function test_limits_and_formats_come_from_the_configuration_and_pdf_is_taken_only_when_enabled(): void
    {
        $this->signIn();
        $pdf = "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n";

        $this->upload(UploadedFile::fake()->createWithContent('price.pdf', $pdf))->assertStatus(422)->assertJsonPath('code', 'media_type_unsupported');

        config()->set('watchdog.site.media_types', ['jpeg', 'pdf', 'tiff']);
        config()->set('watchdog.site.media_max_bytes', 1000);
        $this->getJson('/platform-api/v1/site-media')->assertOk()
            ->assertJsonPath('limits.types', ['jpeg', 'pdf'])
            ->assertJsonPath('limits.extensions', ['jpg', 'pdf'])
            ->assertJsonPath('limits.max_bytes', 1000);

        $document = $this->upload(UploadedFile::fake()->createWithContent('Preise 2026.pdf', $pdf))->assertCreated()
            ->assertJsonPath('kind', 'document')
            ->assertJsonPath('content_type', 'application/pdf')
            ->assertJsonPath('width', null)
            ->json();
        $this->assertStringEndsWith('.pdf', $document['url']);
        $served = $this->get($document['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($pdf, $served->getContent());
        $this->assertStringContainsString('sandbox', (string) $served->headers->get('Content-Security-Policy'));

        $this->upload(UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\n1 0 obj"))->assertStatus(422)->assertJsonPath('code', 'media_unreadable');
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', $this->gd(4, 4, 'imagepng')))->assertStatus(422)->assertJsonPath('code', 'media_type_unsupported');
        $this->upload(UploadedFile::fake()->createWithContent('price.jpg', $pdf))->assertCreated()->assertJsonPath('kind', 'document');
    }

    public function test_files_the_server_cannot_handle_are_refused_instead_of_failing(): void
    {
        $this->signIn();
        config()->set('watchdog.site.media_max_side', 100000);
        config()->set('watchdog.site.media_max_pixels', PHP_INT_MAX);

        $this->upload(UploadedFile::fake()->createWithContent('bomb.png', $this->pngHeader(90000, 90000)))->assertStatus(422)->assertJsonPath('code', 'media_too_large_for_server');
        $this->upload(UploadedFile::fake()->createWithContent('cut.png', $this->pngHeader(20, 20)))->assertStatus(422)->assertJsonPath('code', 'media_unreadable');
        $this->upload(UploadedFile::fake()->createWithContent('empty.jpg', ''))->assertStatus(422);
        $this->post('/platform-api/v1/site-media', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'media_missing');
        $this->call('POST', '/platform-api/v1/site-media', [], [], [], ['CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1), 'HTTP_ACCEPT' => 'application/json'])->assertStatus(413)->assertJsonPath('code', 'media_too_large');
        $this->assertSame(0, SiteMedia::query()->count());
    }

    public function test_an_image_still_on_a_page_or_block_cannot_be_deleted(): void
    {
        $this->signIn();
        $media = $this->upload(UploadedFile::fake()->createWithContent('team.png', $this->gd(4, 4, 'imagepng')))->assertCreated()->json();
        $page = $this->postJson('/platform-api/v1/pages', ['slug' => 'team'])->assertCreated()->json();
        $this->putJson("/platform-api/v1/pages/{$page['id']}/locales/ru/draft", ['title' => 'Команда', 'body' => '<p><img src="'.$media['url'].'" alt="Команда"></p>'])
            ->assertOk()
            ->assertJsonPath('content.ru.draft.body', '<p><img src="'.$media['url'].'" alt="Команда" /></p>');

        $this->postJson("/platform-api/v1/site-media/{$media['id']}/delete")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        DB::table('public_page_versions')->update(['body' => '<p>Ohne Bild</p>']);
        $this->postJson('/platform-api/v1/page-blocks', ['name' => 'Team', 'category' => 'other', 'bodies' => ['de' => '<img src="'.$media['url'].'" alt="">']])->assertCreated();
        $this->postJson("/platform-api/v1/site-media/{$media['id']}/delete")->assertStatus(409)->assertJsonPath('code', 'media_in_use');

        DB::table('page_blocks')->update(['archived_at' => now()]);
        $this->postJson("/platform-api/v1/site-media/{$media['id']}/delete")->assertNoContent();
        $this->get($media['url'])->assertNotFound();
        $this->assertSame([], $this->getJson('/platform-api/v1/site-media')->json('data'));
        $this->postJson("/platform-api/v1/site-media/{$media['id']}/delete")->assertNotFound();
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'site.media.deleted', 'target_id' => $media['id']]);
    }

    public function test_media_needs_content_management(): void
    {
        $this->getJson('/platform-api/v1/site-media')->assertUnauthorized();
        $this->signIn(PlatformStaff::ROLE_ADMIN);
        $this->getJson('/platform-api/v1/site-media')->assertForbidden();
        $this->upload(UploadedFile::fake()->createWithContent('x.png', $this->gd(4, 4, 'imagepng')))->assertForbidden();
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post('/platform-api/v1/site-media', ['file' => $file], ['Accept' => 'application/json']);
    }

    private function gd(int $width, int $height, string $writer): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        $writer($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG with an EXIF block saying "rotate 90° clockwise" and a comment, as a phone would write it.
     */
    private function jpegWithMetadata(int $width, int $height): string
    {
        $jpeg = $this->gd($width, $height, 'imagejpeg');
        $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\x00\x00".pack('V', 0);
        $exif = "Exif\x00\x00".$tiff;
        $comment = 'camera-serial-4711';

        return substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
            ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
            .substr($jpeg, 2);
    }

    /**
     * A PNG signature and header announcing the given size, without image data: what a decompression bomb looks like
     * to `getimagesize`.
     */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER): void
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
