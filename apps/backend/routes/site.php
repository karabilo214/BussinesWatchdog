<?php

use App\Http\Controllers\Site\SignupController;
use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/{locale}/contact', [SiteController::class, 'contact'])->where('locale', 'ru|en|de');
    Route::post('/{locale}/contact', [SiteController::class, 'submitContact'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/signup', [SignupController::class, 'show'])->where('locale', 'ru|en|de');
    Route::post('/{locale}/signup', [SignupController::class, 'store'])->where('locale', 'ru|en|de');
});

Route::middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class])->group(function (): void {
    Route::get('/', [SiteController::class, 'root']);
    Route::get('/{locale}', [SiteController::class, 'home'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/pricing', [SiteController::class, 'pricing'])->where('locale', 'ru|en|de');
    Route::get('/{locale}/{slug}/{child?}', [SiteController::class, 'page'])->where(['locale' => 'ru|en|de', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'child' => '[a-z0-9]+(?:-[a-z0-9]+)*']);
    Route::get('/{locale}/{any}', [SiteController::class, 'missing'])->where(['locale' => 'ru|en|de', 'any' => '.*']);
});
