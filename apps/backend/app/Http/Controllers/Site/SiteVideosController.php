<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Support\Site\SiteContent;
use Illuminate\Http\Response;

class SiteVideosController extends Controller
{
    public const VIDEOS = [
        'checkout' => ['en' => 'en-checkout-check', 'de' => 'de-checkout-pruefung'],
        'provider' => ['en' => 'en-payment-provider-failure', 'de' => 'de-zahlungsanbieter-fehler'],
        'paid' => ['en' => 'en-paid-without-money', 'de' => 'de-bezahlt-ohne-geld'],
    ];

    public function show(string $locale): Response
    {
        app()->setLocale($locale);
        $language = $locale === 'de' ? 'de' : 'en';
        $videos = [];

        foreach (self::VIDEOS as $key => $files) {
            $videos[] = ['key' => $key, 'src' => '/videos/'.$files[$language].'.mp4', 'poster' => '/videos/'.$files[$language].'.webp', 'language' => $language];
        }

        return response()->view('site.videos', [
            'locale' => $locale,
            'path' => 'videos',
            'canonical' => null,
            'alternates' => [],
            'languages' => array_map(fn (string $code): array => ['locale' => $code, 'href' => SiteContent::url($code, 'videos'), 'current' => $code === $locale], PublicPage::LOCALES),
            'header' => app(SiteContent::class)->menu($locale, PublicPage::MENU_HEADER),
            'footer' => app(SiteContent::class)->menu($locale, PublicPage::MENU_FOOTER),
            'preview' => false,
            'title' => trans('site.videos.meta_title', [], $locale),
            'description' => trans('site.videos.meta_description', [], $locale),
            'videos' => $videos,
            'videoLanguageNote' => $language !== $locale,
        ])->header('Cache-Control', 'public, max-age='.(int) config('watchdog.site.cache_seconds'));
    }
}
