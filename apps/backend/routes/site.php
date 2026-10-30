<?php

use App\Http\Controllers\Site\PluginLinkController;
use App\Http\Controllers\Site\PublicStorefrontCheckController;
use App\Http\Controllers\Site\SignupController;
use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\Site\SiteMediaController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/go/wordpress-plugin', [PluginLinkController::class, 'go'])->middleware('throttle:30,1');
    Route::get('/connect/woocommerce', [PluginLinkController::class, 'connect'])->middleware('throttle:30,1');
    Route::get('/{locale}/contact', [SiteController::class, 'contact'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/contact/captcha', [SiteController::class, 'contactCaptcha'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/contact/captcha/text', [SiteController::class, 'contactCaptchaText'])->where('locale', 'ru|en|de');
    Route::post('/{locale}/contact', [SiteController::class, 'submitContact'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/check-store', [PublicStorefrontCheckController::class, 'form'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/check-store/captcha', [PublicStorefrontCheckController::class, 'captcha'])->where('locale', 'ru|en|de');
    Route::post('/{locale}/check-store', [PublicStorefrontCheckController::class, 'submit'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/check-store/{token}/status', [PublicStorefrontCheckController::class, 'status'])->where(['locale' => 'ru|en|de', 'token' => '[0-9a-f]{64}']);
    Route::get('/{locale}/check-store/{token}', [PublicStorefrontCheckController::class, 'result'])->where(['locale' => 'ru|en|de', 'token' => '[0-9a-f]{64}']);
    Route::get('/{locale}/check-store/{token}/artifacts/{artifactId}', [PublicStorefrontCheckController::class, 'artifact'])->where(['locale' => 'ru|en|de', 'token' => '[0-9a-f]{64}', 'artifactId' => '[0-9a-fA-F-]{36}']);
    Route::get('/{locale}/signup', [SignupController::class, 'show'])->where('locale', 'ru|en|de');
    Route::post('/{locale}/signup', [SignupController::class, 'store'])->where('locale', 'ru|en|de');
});

Route::middleware([SubstituteBindings::class])->group(function (): void {
    Route::get('/', [SiteController::class, 'root']);
    Route::get('/sitemap.xml', [SitemapController::class, 'sitemap']);
    Route::get('/robots.txt', [SitemapController::class, 'robots']);
    Route::get('/media/{media}.{extension}', [SiteMediaController::class, 'show'])->where(['media' => '[0-9a-f-]{36}', 'extension' => 'jpg|png|webp|pdf']);
    Route::get('/{locale}', [SiteController::class, 'home'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/pricing', [SiteController::class, 'pricing'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/pay', [SiteController::class, 'pay'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/videos/{video}', [SiteController::class, 'video'])->where(['locale' => 'ru|en|de', 'video' => implode('|', array_keys((require database_path('data/videos.php'))['videos']))]);
    Route::get('/{locale}/{slug}/{child?}', [SiteController::class, 'page'])->where(['locale' => 'ru|en|de', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'child' => '[a-z0-9]+(?:-[a-z0-9]+)*']);
    Route::get('/{locale}/{any}', [SiteController::class, 'missing'])->where(['locale' => 'ru|en|de', 'any' => '.*']);
});
