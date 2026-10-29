<?php

namespace App\Support\Site;

/**
 * The stylesheet of the public site built by `apps/frontend/public` into `public/site` (README «Фронтенды»). Without a
 * build the pages are still served, unstyled.
 */
final class SiteAssets
{
    public const DIRECTORY = 'site';

    public const ENTRY = 'src/site.css';

    /**
     * The stylesheet itself, for the preview in the owner panel: it is shown in a sandboxed frame that may not reach
     * the site origin, so the rules travel inside the document.
     */
    public static function inline(): ?string
    {
        $file = self::file();

        if ($file === null || ! is_file(public_path(self::DIRECTORY.'/'.$file))) {
            return null;
        }

        return str_replace('</', '<\\/', (string) file_get_contents(public_path(self::DIRECTORY.'/'.$file)));
    }

    /**
     * @return list<string>
     */
    public static function stylesheets(bool $absolute = false): array
    {
        $file = self::file();

        if ($file === null) {
            return [];
        }

        $path = '/'.self::DIRECTORY.'/'.ltrim($file, '/');

        return [$absolute ? rtrim((string) config('app.url'), '/').$path : $path];
    }

    /** The small Trusted Types bootstrap imported before the public checkout script runs. */
    public static function paddleScript(): ?string
    {
        $file = self::file('src/paddle.ts');

        return $file === null ? null : '/'.self::DIRECTORY.'/'.ltrim($file, '/');
    }

    private static function file(string $entry = self::ENTRY): ?string
    {
        $manifest = public_path(self::DIRECTORY.'/.vite/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $file = is_array($entries) ? ($entries[$entry]['file'] ?? null) : null;

        return is_string($file) ? $file : null;
    }
}
