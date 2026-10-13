<?php

namespace App\Support\Site;

use App\Models\PublicPage;
use App\Models\PublicPageVersion;

/**
 * What the public site shows: the published version of a page in a language, the header and footer menus and the
 * languages a page exists in. Drafts are never read here, nor stored versions of pages changed in code.
 */
class SiteContent
{
    public function page(string $path): ?PublicPage
    {
        /** @var PublicPage|null $page */
        $page = PublicPage::query()->where('path', $path)->whereNull('archived_at')->first();

        return $page;
    }

    public function system(string $kind): ?PublicPage
    {
        /** @var PublicPage|null $page */
        $page = PublicPage::query()->where('kind', $kind)->first();

        return $page;
    }

    public function published(?PublicPage $page, string $locale): ?PublicPageVersion
    {
        if ($page === null || ! $page->isEditable()) {
            return null;
        }

        /** @var PublicPageVersion|null $version */
        $version = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_PUBLISHED)->first();

        return $version;
    }

    /**
     * @return list<string>
     */
    public function locales(PublicPage $page): array
    {
        if ($page->isSystem()) {
            return PublicPage::LOCALES;
        }

        $published = PublicPageVersion::query()->where('page_id', $page->id)->where('state', PublicPageVersion::STATE_PUBLISHED)->pluck('locale')->all();

        return array_values(array_intersect(PublicPage::LOCALES, $published));
    }

    /**
     * @return list<array{href: string, label: string, path: string}>
     */
    public function menu(string $locale, string $menu): array
    {
        $pages = PublicPage::query()->where('menu', $menu)->whereNull('archived_at')->where('kind', '!=', PublicPage::KIND_HOME)->orderBy('sort')->orderBy('path')->get();
        $titles = PublicPageVersion::query()->whereIn('page_id', $pages->pluck('id'))->where('locale', $locale)->where('state', PublicPageVersion::STATE_PUBLISHED)->pluck('title', 'page_id');
        $items = [];

        foreach ($pages as $page) {
            $label = ($page->isEditable() ? $titles[$page->id] ?? null : null) ?? ($page->isSystem() ? trans("site.nav.{$page->kind}", [], $locale) : null);

            if ($label !== null) {
                $items[] = ['href' => self::url($locale, $page->path), 'label' => (string) $label, 'path' => $page->path];
            }
        }

        return $items;
    }

    public static function url(string $locale, string $path = ''): string
    {
        return '/'.$locale.($path === '' ? '' : '/'.$path);
    }
}
