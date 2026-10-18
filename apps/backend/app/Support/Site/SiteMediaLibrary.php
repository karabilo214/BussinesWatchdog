<?php

namespace App\Support\Site;

use App\Models\PageBlock;
use App\Models\PublicPageVersion;
use App\Models\SiteMedia;
use App\Support\Platform\PlatformAudit;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files uploaded from the page editor (ADR 0063). Formats and limits come from the configuration. A file is recognised
 * by its content, not by the name or the type the browser sends. Images are decoded and encoded again — so EXIF/XMP
 * data (camera, GPS, author) and anything hidden in the file do not reach the public site — and made smaller when they
 * exceed the configured side. A PDF, when allowed, is checked for its structure and stored as it is. The public site
 * serves the files under `/media/{id}.{ext}`; pages keep that relative address, so a domain change does not break them.
 */
class SiteMediaLibrary
{
    public const LIST_LIMIT = 200;

    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    /** @var array<string, array{content_type: string, extension: string, kind: string}> */
    public const FORMATS = [
        'jpeg' => ['content_type' => 'image/jpeg', 'extension' => 'jpg', 'kind' => self::KIND_IMAGE],
        'png' => ['content_type' => 'image/png', 'extension' => 'png', 'kind' => self::KIND_IMAGE],
        'webp' => ['content_type' => 'image/webp', 'extension' => 'webp', 'kind' => self::KIND_IMAGE],
        'pdf' => ['content_type' => 'application/pdf', 'extension' => 'pdf', 'kind' => self::KIND_DOCUMENT],
    ];

    private const IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** Bytes GD needs per pixel of a true-colour image, with headroom for its own bookkeeping. */
    private const BYTES_PER_PIXEL = 5;

    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * The limits in force: the configuration, never above what PHP itself accepts for an upload.
     *
     * @return array{types: list<string>, extensions: list<string>, content_types: list<string>, max_bytes: int, max_side: int, max_pixels: int, resize_max_side: int}
     */
    public function limits(): array
    {
        $types = array_values(array_intersect(array_keys(self::FORMATS), (array) config('watchdog.site.media_types')));
        $phpLimit = min(array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')], fn (int $bytes): bool => $bytes > 0) ?: [PHP_INT_MAX]);

        return [
            'types' => $types,
            'extensions' => array_map(fn (string $type): string => self::FORMATS[$type]['extension'], $types),
            'content_types' => array_map(fn (string $type): string => self::FORMATS[$type]['content_type'], $types),
            'max_bytes' => max(1, min((int) config('watchdog.site.media_max_bytes'), $phpLimit)),
            'max_side' => max(1, (int) config('watchdog.site.media_max_side')),
            'max_pixels' => max(1, (int) config('watchdog.site.media_max_pixels')),
            'resize_max_side' => max(0, (int) config('watchdog.site.media_resize_max_side')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return SiteMedia::query()->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (SiteMedia $media): array => $this->card($media))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(string $staffId, UploadedFile $file): array
    {
        $limits = $this->limits();

        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw new SiteRejected('media_too_large');
        }

        $path = $file->getRealPath();

        if ($path === false || ! $file->isValid()) {
            throw new SiteRejected('media_unreadable');
        }

        if ((int) $file->getSize() > $limits['max_bytes']) {
            throw new SiteRejected('media_too_large');
        }

        $type = $this->detect($path);

        if ($type === null || ! in_array($type, $limits['types'], true)) {
            throw new SiteRejected('media_type_unsupported');
        }

        $format = self::FORMATS[$type];
        $body = $format['kind'] === self::KIND_DOCUMENT ? $this->document($path) : $this->image($path, $type, $limits);
        $id = (string) Str::uuid7();
        $key = 'site-media/'.$id.'.'.$format['extension'];

        try {
            $this->disk()->put($key, $body['bytes'], ['visibility' => 'private', 'ContentType' => $format['content_type']]);
        } catch (Throwable $exception) {
            report($exception);

            throw new SiteRejected('media_storage_unavailable', 503);
        }

        $media = SiteMedia::query()->create([
            'id' => $id,
            'object_key' => $key,
            'content_type' => $format['content_type'],
            'extension' => $format['extension'],
            'width' => $body['width'],
            'height' => $body['height'],
            'bytes' => strlen($body['bytes']),
            'sha256' => hash('sha256', $body['bytes']),
            'original_name' => $this->originalName($file),
            'uploaded_by' => $staffId,
        ]);
        $this->audit->record($staffId, 'site.media.uploaded', 'site_media', $media->id, PlatformAudit::RESULT_SUCCEEDED, [
            'name' => $media->original_name,
            'content_type' => $media->content_type,
            'bytes' => $media->bytes,
            'original_bytes' => (int) $file->getSize(),
            'sha256' => $media->sha256,
        ]);

        return $this->card($media);
    }

    public function delete(string $staffId, string $mediaId): void
    {
        $media = $this->find($mediaId);

        if ($this->inUse($media)) {
            throw new SiteRejected('media_in_use', 409);
        }

        $this->disk()->delete($media->object_key);
        $media->forceFill(['deleted_at' => Carbon::now()])->save();
        $this->audit->record($staffId, 'site.media.deleted', 'site_media', $media->id, PlatformAudit::RESULT_SUCCEEDED, ['name' => $media->original_name, 'sha256' => $media->sha256]);
    }

    /**
     * @return array{body: string, content_type: string, name: string}|null
     */
    public function served(string $mediaId, string $extension): ?array
    {
        /** @var SiteMedia|null $media */
        $media = Str::isUuid($mediaId) ? SiteMedia::query()->whereKey($mediaId)->where('extension', $extension)->whereNull('deleted_at')->first() : null;
        $body = $media === null ? null : $this->disk()->get($media->object_key);

        return $body === null ? null : ['body' => $body, 'content_type' => $media->content_type, 'name' => $media->original_name];
    }

    /**
     * A page version (any state, the archive included) or a block template that still contains the address.
     */
    public function inUse(SiteMedia $media): bool
    {
        $needle = '%'.$media->id.'%';

        return PublicPageVersion::query()->where('body', 'like', $needle)->exists()
            || PageBlock::query()->whereNull('archived_at')->whereRaw('CAST(bodies AS TEXT) LIKE ?', [$needle])->exists();
    }

    private function detect(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 1024);

        if (str_starts_with(ltrim($head, "\xEF\xBB\xBF\r\n\t "), '%PDF-')) {
            return 'pdf';
        }

        $info = @getimagesize($path);

        return is_array($info) ? (self::IMAGE_TYPES[$info[2]] ?? null) : null;
    }

    /**
     * A PDF is not rewritten (that needs tools outside PHP); it must at least look like a complete PDF.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function document(string $path): array
    {
        $bytes = (string) @file_get_contents($path);

        if ($bytes === '' || ! str_contains(substr($bytes, -2048), '%%EOF')) {
            throw new SiteRejected('media_unreadable');
        }

        return ['bytes' => $bytes, 'width' => 0, 'height' => 0];
    }

    /**
     * @param  array{max_side: int, max_pixels: int, resize_max_side: int}  $limits
     * @return array{bytes: string, width: int, height: int}
     */
    private function image(string $path, string $type, array $limits): array
    {
        [$width, $height] = (array) @getimagesize($path) + [0, 0];

        if ($width < 1 || $height < 1 || $width > $limits['max_side'] || $height > $limits['max_side'] || $width * $height > $limits['max_pixels']) {
            throw new SiteRejected('media_dimensions_too_large');
        }

        $reader = ['jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$type];
        $writer = ['jpeg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'][$type];

        if (! function_exists($reader) || ! function_exists($writer)) {
            throw new SiteRejected('media_processing_unavailable', 503);
        }

        if (! $this->memoryAllows($width * $height * 2)) {
            throw new SiteRejected('media_too_large_for_server');
        }

        try {
            $image = @$reader($path);

            if (! $image instanceof GdImage) {
                throw new SiteRejected('media_unreadable');
            }

            $image = $type === 'jpeg' ? $this->upright($image, $path) : $this->keepAlpha($image);
            $image = $this->fit($image, $limits['resize_max_side']);
            $bytes = $this->encode($image, $type, $writer);
        } catch (SiteRejected $rejected) {
            throw $rejected;
        } catch (Throwable $exception) {
            report($exception);

            throw new SiteRejected('media_unreadable');
        }

        return ['bytes' => $bytes, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    private function encode(GdImage $image, string $type, string $writer): string
    {
        $quality = match ($type) {
            'jpeg' => min(100, max(1, (int) config('watchdog.site.media_jpeg_quality'))),
            'webp' => min(100, max(1, (int) config('watchdog.site.media_webp_quality'))),
            default => min(9, max(0, (int) config('watchdog.site.media_png_compression'))),
        };

        ob_start();

        try {
            $written = $writer($image, null, $quality);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if (! $written || $bytes === '') {
            throw new SiteRejected('media_unreadable');
        }

        return $bytes;
    }

    /**
     * Makes the image smaller so its longer side is at most the configured side (0 keeps the size).
     */
    private function fit(GdImage $image, int $maxSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($maxSide < 1 || max($width, $height) <= $maxSide) {
            return $image;
        }

        $scale = $maxSide / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function keepAlpha(GdImage $image): GdImage
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Photos are often stored sideways with an EXIF orientation; it is applied before the metadata is dropped.
     */
    private function upright(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $angle = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * Decoding a large image needs memory that PHP cannot give back once it runs out (a fatal error, not an
     * exception), so the need is checked against the memory limit first. Two copies may exist while rotating or
     * scaling.
     */
    private function memoryAllows(int $pixels): bool
    {
        $limit = self::iniBytes('memory_limit');

        return $limit <= 0 || $pixels * self::BYTES_PER_PIXEL + 8 * 1024 * 1024 <= $limit - memory_get_usage(true);
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function originalName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName())) ?? '';

        return mb_substr($name === '' ? 'file' : $name, 0, 200);
    }

    private function find(string $mediaId): SiteMedia
    {
        /** @var SiteMedia|null $media */
        $media = Str::isUuid($mediaId) ? SiteMedia::query()->whereKey($mediaId)->whereNull('deleted_at')->first() : null;

        return $media ?? throw new SiteRejected('media_not_found', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(SiteMedia $media): array
    {
        $document = $media->content_type === self::FORMATS['pdf']['content_type'];

        return [
            'id' => $media->id,
            'url' => $media->path(),
            'kind' => $document ? self::KIND_DOCUMENT : self::KIND_IMAGE,
            'name' => $media->original_name,
            'content_type' => $media->content_type,
            'width' => $document ? null : $media->width,
            'height' => $document ? null : $media->height,
            'bytes' => $media->bytes,
            'created_at' => $media->created_at?->toJSON(),
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.site.media_disk'));
    }
}
