<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}{{ $title === trans('site.brand') || str_contains($title, trans('site.brand')) ? '' : ' — '.trans('site.brand') }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($preview)
        <meta name="robots" content="noindex, nofollow">
        <base target="_blank">
    @endif
    @if (($path ?? null) === 'check-store')
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    @foreach ($alternates as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['locale'] }}" href="{{ $alternate['href'] }}">
    @endforeach
    @if ($preview)
        <style>{!! \App\Support\Site\SiteAssets::inline() !!}</style>
    @else
        @foreach (\App\Support\Site\SiteAssets::stylesheets() as $stylesheet)
            <link rel="stylesheet" href="{{ $stylesheet }}">
        @endforeach
    @endif
</head>
<body class="site">
<a class="site-skip" href="#content">{{ __('site.nav.skip') }}</a>
@if ($preview)
    <p class="site-preview" role="status">{{ __('site.preview') }}</p>
@endif
<header class="site-header">
    <div class="site-wrap site-header-row">
        <a class="site-brand" href="{{ \App\Support\Site\SiteContent::url($locale) }}">{{ __('site.brand') }}</a>
        <nav aria-label="{{ __('site.nav.main') }}" class="site-nav site-nav-wide">
            @include('site.menu')
        </nav>
        <details class="site-menu">
            <summary>{{ __('site.nav.menu') }}</summary>
            <nav aria-label="{{ __('site.nav.main') }}" class="site-nav">
                @include('site.menu')
            </nav>
        </details>
    </div>
</header>
<main id="content" class="site-main">
    @yield('content')
</main>
<footer class="site-footer">
    <div class="site-wrap site-footer-row">
        @if ($footer !== [])
            <nav aria-label="{{ __('site.nav.footer') }}" class="site-footer-nav">
                @foreach ($footer as $item)
                    <a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
        @endif
        <nav aria-label="{{ __('site.nav.languages') }}" class="site-languages">
            @foreach ($languages as $language)
                <a href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" @if ($language['current']) aria-current="true" @endif>{{ strtoupper($language['locale']) }}</a>
            @endforeach
        </nav>
        <p class="site-copy">© {{ now()->year }} {{ __('site.brand') }}</p>
    </div>
</footer>
</body>
</html>
