<?php

namespace App\Support\Site;

use App\Models\PageBlock;
use App\Models\PublicPageVersion;
use App\Models\SiteMedia;
use App\Support\Platform\PlatformAudit;
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

    public const KIND_IMAGE = SafeFiles::KIND_IMAGE;

    public const KIND_DOCUMENT = SafeFiles::KIND_DOCUMENT;

    public const FORMATS = SafeFiles::FORMATS;

    public function __construct(private readonly PlatformAudit $audit, private readonly SafeFiles $files) {}

    /**
     * The limits in force: the configuration, never above what PHP itself accepts for an upload.
     *
     * @return array{types: list<string>, extensions: list<string>, content_types: list<string>, max_bytes: int, max_side: int, max_pixels: int, resize_max_side: int}
     */
    public function limits(): array
    {
        $types = array_values(array_intersect(array_keys(self::FORMATS), (array) config('watchdog.site.media_types')));
        $phpLimit = SafeFiles::phpUploadLimit();

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
        $body = $this->files->clean($file, $limits['types'], $limits['max_bytes'], $limits);
        $format = self::FORMATS[$body['type']];
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
            'original_name' => $this->files->originalName($file),
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
