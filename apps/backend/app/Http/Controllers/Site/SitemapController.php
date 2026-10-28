<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Support\Site\SiteContent;
use Illuminate\Http\Response;

/**
 * `/sitemap.xml` and `/robots.txt` of the public site: every page that is shown in a language (system pages in all
 * languages, other pages where a version is published, the videos page), with its other languages as hreflang alternates. Absolute
 * addresses come from APP_URL, so a domain change needs no code. Forms and result pages (sign-up, store check, pay)
 * are left out.
 */
class SitemapController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    public function sitemap(): Response
    {
        $origin = rtrim((string) config('app.url'), '/');
        $pages = PublicPage::query()->whereNull('archived_at')->orderByRaw("CASE kind WHEN 'home' THEN 0 WHEN 'pricing' THEN 1 WHEN 'contact' THEN 2 ELSE 3 END")->orderBy('path')->get();
        $published = PublicPageVersion::query()->whereIn('page_id', $pages->pluck('id'))->where('state', PublicPageVersion::STATE_PUBLISHED)->get(['page_id', 'locale', 'published_at'])->groupBy('page_id');
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];

        foreach ($pages as $page) {
            $locales = $this->content->locales($page);
            $versions = ($published[$page->id] ?? collect())->keyBy('locale');

            foreach ($locales as $locale) {
                $lastmod = $page->isEditable() ? $versions->get($locale)?->published_at : null;
                $xml[] = '  <url>';
                $xml[] = '    <loc>'.e($origin.SiteContent::url($locale, $page->path)).'</loc>';

                if ($lastmod !== null) {
                    $xml[] = '    <lastmod>'.$lastmod->utc()->format('Y-m-d').'</lastmod>';
                }

                foreach ($locales as $alternate) {
                    $xml[] = '    <xhtml:link rel="alternate" hreflang="'.$alternate.'" href="'.e($origin.SiteContent::url($alternate, $page->path)).'"/>';
                }

                $xml[] = '  </url>';
            }
        }

        foreach (PublicPage::LOCALES as $locale) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.e($origin.SiteContent::url($locale, 'videos')).'</loc>';

            foreach (PublicPage::LOCALES as $alternate) {
                $xml[] = '    <xhtml:link rel="alternate" hreflang="'.$alternate.'" href="'.e($origin.SiteContent::url($alternate, 'videos')).'"/>';
            }

            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.(int) config('watchdog.site.cache_seconds'),
        ]);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow:\n\nSitemap: ".rtrim((string) config('app.url'), '/')."/sitemap.xml\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.(int) config('watchdog.site.cache_seconds'),
        ]);
    }
}
