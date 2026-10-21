<?php

namespace App\Support\Site;

use GdImage;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Uploaded files from outside (ADR 0063): the page editor and the contact form. A file is recognised by its content,
 * not by the name or the type the browser sends. Images are decoded and encoded again — so EXIF/XMP data (camera, GPS,
 * author) and anything hidden in the file are dropped — and made smaller when they exceed the given side. A PDF is
 * checked for its structure and kept as it is. Rejections are SiteRejected with a `media_*` code.
 */
class SafeFiles
{
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

    /**
     * The largest upload PHP itself accepts.
     */
    public static function phpUploadLimit(): int
    {
        return min(array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')], fn (int $bytes): bool => $bytes > 0) ?: [PHP_INT_MAX]);
    }

    /**
     * @param  list<string>  $types
     * @param  array{max_side: int, max_pixels: int, resize_max_side: int}  $limits
     * @return array{type: string, content_type: string, extension: string, kind: string, bytes: string, width: int, height: int}
     */
    public function clean(UploadedFile $file, array $types, int $maxBytes, array $limits): array
    {
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw new SiteRejected('media_too_large');
        }

        $path = $file->getRealPath();

        if ($path === false || ! $file->isValid()) {
            throw new SiteRejected('media_unreadable');
        }

        if ((int) $file->getSize() > $maxBytes) {
            throw new SiteRejected('media_too_large');
        }

        $type = $this->detect($path);

        if ($type === null || ! in_array($type, $types, true)) {
            throw new SiteRejected('media_type_unsupported');
        }

        $format = self::FORMATS[$type];
        $body = $format['kind'] === self::KIND_DOCUMENT ? $this->document($path) : $this->image($path, $type, $limits);

        return ['type' => $type] + $format + $body;
    }

    public function originalName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName())) ?? '';

        return mb_substr($name === '' ? 'file' : $name, 0, 200);
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
}
