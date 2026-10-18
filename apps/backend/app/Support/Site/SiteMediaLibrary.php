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

/**
 * Images uploaded from the page editor (ADR 0063). Only JPEG, PNG and WebP are taken, recognised by their content,
 * not by the name or the type the browser sends; every image is decoded and encoded again, so EXIF/XMP data (camera,
 * GPS, author) and anything hidden in the file do not reach the public site. The public site serves them under
 * `/media/{id}.{ext}`; pages keep that relative address, so a domain change does not break them.
 */
class SiteMediaLibrary
{
    public const LIST_LIMIT = 200;

    private const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    public function __construct(private readonly PlatformAudit $audit) {}

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
        $path = $file->getRealPath();

        if ($path === false || ! $file->isValid()) {
            throw new SiteRejected('media_unreadable');
        }

        if ($file->getSize() > (int) config('watchdog.site.media_max_bytes')) {
            throw new SiteRejected('media_too_large');
        }

        $info = @getimagesize($path);
        $type = is_array($info) ? $info[2] : null;

        if (! isset(self::TYPES[$type])) {
            throw new SiteRejected('media_type_unsupported');
        }

        [$width, $height] = $info;
        $maxSide = (int) config('watchdog.site.media_max_side');

        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide || $width * $height > (int) config('watchdog.site.media_max_pixels')) {
            throw new SiteRejected('media_dimensions_too_large');
        }

        [$contentType, $extension] = self::TYPES[$type];
        $body = $this->reencode($path, $type);
        $id = (string) Str::uuid7();
        $key = 'site-media/'.$id.'.'.$extension;
        $this->disk()->put($key, $body['bytes'], ['visibility' => 'private', 'ContentType' => $contentType]);

        $media = SiteMedia::query()->create([
            'id' => $id,
            'object_key' => $key,
            'content_type' => $contentType,
            'extension' => $extension,
            'width' => $body['width'],
            'height' => $body['height'],
            'bytes' => strlen($body['bytes']),
            'sha256' => hash('sha256', $body['bytes']),
            'original_name' => $this->originalName($file),
            'uploaded_by' => $staffId,
        ]);
        $this->audit->record($staffId, 'site.media.uploaded', 'site_media', $media->id, PlatformAudit::RESULT_SUCCEEDED, [
            'name' => $media->original_name,
            'content_type' => $contentType,
            'bytes' => $media->bytes,
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
     * @return array{body: string, content_type: string}|null
     */
    public function served(string $mediaId, string $extension): ?array
    {
        /** @var SiteMedia|null $media */
        $media = Str::isUuid($mediaId) ? SiteMedia::query()->whereKey($mediaId)->where('extension', $extension)->whereNull('deleted_at')->first() : null;
        $body = $media === null ? null : $this->disk()->get($media->object_key);

        return $body === null ? null : ['body' => $body, 'content_type' => $media->content_type];
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

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function reencode(string $path, int $type): array
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };

        if (! $image instanceof GdImage) {
            throw new SiteRejected('media_unreadable');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->upright($image, $path);
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 85),
            IMAGETYPE_PNG => imagepng($image, null, 9),
            default => imagewebp($image, null, 85),
        };
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            throw new SiteRejected('media_unreadable');
        }

        return ['bytes' => $bytes, 'width' => imagesx($image), 'height' => imagesy($image)];
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

    private function originalName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName())) ?? '';

        return mb_substr($name === '' ? 'image' : $name, 0, 200);
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
        return [
            'id' => $media->id,
            'url' => $media->path(),
            'name' => $media->original_name,
            'content_type' => $media->content_type,
            'width' => $media->width,
            'height' => $media->height,
            'bytes' => $media->bytes,
            'created_at' => $media->created_at?->toJSON(),
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.site.media_disk'));
    }
}
