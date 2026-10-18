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
