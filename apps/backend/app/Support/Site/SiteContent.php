<?php

namespace App\Support\Site;

use App\Models\PublicMenuSection;
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
     * @return list<array{href: ?string, label: string, path: ?string, parent?: ?string, section?: ?string, footer_group?: string, sort?: int, children: list<array{href: string, label: string, path: string}>}>
     */
    public function menu(string $locale, string $menu): array
    {
        $pages = PublicPage::query()->where('menu', $menu)->whereNull('archived_at')->where('kind', '!=', PublicPage::KIND_HOME)->orderBy('sort')->orderBy('path')->get();
        $titles = PublicPageVersion::query()->whereIn('page_id', $pages->pluck('id'))->where('locale', $locale)->where('state', PublicPageVersion::STATE_PUBLISHED)->pluck('title', 'page_id');
        $items = [];

        foreach ($pages as $page) {
            $title = ($page->isEditable() ? $titles[$page->id] ?? null : null) ?? ($page->isSystem() ? trans("site.nav.{$page->kind}", [], $locale) : null);

            if ($title !== null) {
                $label = $page->menu_labels[$locale] ?? $title;
                $items[$page->id] = ['href' => self::url($locale, $page->path), 'label' => (string) $label, 'path' => $page->path, 'parent' => $page->menu_parent_id, 'section' => $page->menu_section_id, 'sort' => $page->sort, 'children' => []];
                if ($menu === PublicPage::MENU_FOOTER) {
                    $items[$page->id]['footer_group'] = $page->footerGroup();
                }
            }
        }

        if ($menu === PublicPage::MENU_FOOTER) {
            return array_values($items);
        }

        $sections = [];
        foreach (PublicMenuSection::query()->where('menu', $menu)->orderBy('sort')->orderBy('id')->get() as $section) {
            $sections[$section->id] = ['href' => null, 'path' => null, 'label' => $section->labels[$locale], 'sort' => $section->sort, 'children' => []];
        }
        foreach ($items as $id => $item) {
            if ($item['section'] !== null && isset($sections[$item['section']])) {
                $sections[$item['section']]['children'][] = ['href' => $item['href'], 'label' => $item['label'], 'path' => $item['path']];
                unset($items[$id]);
            }
        }

        foreach ($items as $id => $item) {
            if ($item['parent'] !== null && isset($items[$item['parent']]) && $items[$item['parent']]['parent'] === null) {
                $items[$item['parent']]['children'][] = ['href' => $item['href'], 'label' => $item['label'], 'path' => $item['path']];
                unset($items[$id]);
            }
        }

        $roots = array_merge(array_values($items), array_values(array_filter($sections, fn ($section) => $section['children'] !== [])));
        usort($roots, fn ($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['path'] ?? $a['label'], $b['path'] ?? $b['label']));

        return array_map(fn (array $item): array => ['href' => $item['children'] === [] ? $item['href'] : null, 'label' => $item['label'], 'path' => $item['children'] === [] ? $item['path'] : null, 'children' => $item['children']], $roots);
    }

    /**
     * @return array<string, array{label: string, items: list<array{href: string, label: string, path: string}>}>
     */
    public function footerGroups(string $locale): array
    {
        $groups = [];
        $keys = [];
        foreach (PublicMenuSection::query()->where('menu', PublicPage::MENU_FOOTER)->orderBy('sort')->orderBy('id')->get() as $section) {
            $key = $section->footer_key ?? $section->id;
            $keys[$section->id] = $key;
            $groups[$key] = ['label' => $section->labels[$locale], 'items' => []];
        }
        foreach ($this->menu($locale, PublicPage::MENU_FOOTER) as $item) {
            $key = $keys[$item['section']] ?? $item['footer_group'];
            $groups[$key]['items'][] = ['href' => $item['href'], 'label' => $item['label'], 'path' => $item['path']];
        }
        $groups = array_filter($groups, fn ($group) => $group['items'] !== []);

        return $groups;
    }

    public static function url(string $locale, string $path = ''): string
    {
        return '/'.$locale.($path === '' ? '' : '/'.$path);
    }
}
