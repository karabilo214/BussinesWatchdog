<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.png" type="image/png" sizes="64x64">
    <title>{{ $title }}{{ $title === trans('site.brand') || str_contains($title, trans('site.brand')) ? '' : ' — '.trans('site.brand') }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($preview)
        <meta name="robots" content="noindex, nofollow">
        <base target="_blank">
    @endif
    @if (in_array($path ?? null, ['check-store', 'pay'], true) && ! ($indexable ?? false))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if ($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    @foreach ($alternates as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['locale'] }}" href="{{ $alternate['href'] }}">
    @endforeach

    <style>{!! \App\Support\Site\SiteAssets::inline() !!}</style>

</head>
<body class="site">
<a class="site-skip" href="#content">{{ __('site.nav.skip') }}</a>
@if ($preview)
    <p class="site-preview" role="status">{{ __('site.preview') }}</p>
@endif
@php($maintenance = $preview ? null : app(\App\Support\Platform\PlatformSettings::class)->forCustomer($locale))
@if ($maintenance)
    <p class="site-preview" role="status" data-maintenance>{{ __($maintenance['active'] ? 'site.maintenance.active' : 'site.maintenance.planned', ['from' => \Illuminate\Support\Carbon::parse($maintenance['starts_at'])->timezone('Europe/Berlin')->format('d.m.Y H:i'), 'to' => \Illuminate\Support\Carbon::parse($maintenance['ends_at'])->timezone('Europe/Berlin')->format('d.m.Y H:i T')]) }} {{ $maintenance['message'] }}</p>
@endif
<header class="site-header">
    <div class="site-wrap site-header-row">
        <a class="site-brand" href="{{ \App\Support\Site\SiteContent::url($locale) }}">
            <img class="site-brand-mark" src="/images/brand-mark-64.webp" srcset="/images/brand-mark-128.webp 2x" alt="" width="36" height="36" decoding="async">
            <span>{{ __('site.brand') }}</span>
        </a>
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
        <div class="site-footer-groups">
            @foreach ($footer as $group => $section)
                <nav aria-labelledby="footer-{{ $group }}" class="site-footer-group" data-footer-group="{{ $group }}">
                    <h2 id="footer-{{ $group }}">{{ $section['label'] }}</h2>
                    <ul class="site-footer-nav">
                        @foreach ($section['items'] as $item)
                            <li><a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
        <nav aria-label="{{ __('site.nav.languages') }}" class="site-languages">
            @foreach ($languages as $language)
                <a href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" @if ($language['current']) aria-current="true" @endif>{{ strtoupper($language['locale']) }}</a>
            @endforeach
        </nav>
        <p class="site-copy">© {{ now()->year }} {{ __('site.brand') }}</p>
    </div>
</footer>
@if (collect($header)->contains(fn ($item) => $item['children'] !== []))
<script>
document.addEventListener('click', (event) => document.querySelectorAll('details[data-dropdown][open]').forEach((menu) => menu.contains(event.target) || menu.removeAttribute('open')));
document.addEventListener('keydown', (event) => event.key === 'Escape' && document.querySelectorAll('details[data-dropdown][open]').forEach((menu) => { menu.removeAttribute('open'); menu.querySelector('summary').focus(); }));
document.querySelectorAll('details[data-dropdown]').forEach((menu) => menu.addEventListener('toggle', () => menu.open && document.querySelectorAll('details[data-dropdown][open]').forEach((other) => other === menu || other.removeAttribute('open'))));
</script>
@endif
@stack('scripts')
</body>
</html>
