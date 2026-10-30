@extends('site.layout')

@push('head')
    <meta property="og:type" content="video.other">
    <meta property="og:video" content="{{ rtrim((string) config('app.url'), '/').$video['mp4'] }}">
    <meta property="og:image" content="{{ rtrim((string) config('app.url'), '/').$video['poster'] }}">
    <script type="application/ld+json" nonce="{{ request()->attributes->get('csp_nonce') }}">{!! $structured !!}</script>
@endpush

@section('content')
    <section class="site-wrap site-section site-watch">
        <p class="site-muted">{{ $copy['eyebrow'] }}</p>
        <h1>{{ $video['h1'] }}</h1>
        <video controls playsinline preload="metadata" width="1280" height="720" poster="{{ $video['poster'] }}" class="site-video-player">
            <source src="{{ $video['mp4'] }}" type="video/mp4">
        </video>
        <p class="site-lead">{{ $video['description'] }}</p>
        @if ($copy['lang_note'])
            <p class="site-muted site-small">{{ $copy['lang_note'] }}</p>
        @endif
        <h2>{{ $copy['what'] }}</h2>
        <ol>
            @foreach ($video['steps'] as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        <p class="site-muted site-small">{{ $copy['note'] }} <a href="{{ $video['mp4'] }}" download>{{ $copy['download'] }}</a></p>
        <p class="site-video-actions"><a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ $copy['start'] }}</a> <a href="{{ \App\Support\Site\SiteContent::url($locale, 'check-store') }}">{{ $copy['check'] }}</a></p>
        <h2>{{ $copy['more'] }}</h2>
        <ul class="site-video-list">
            @foreach ($others as $other)
                <li><a href="{{ $other['href'] }}"><img src="{{ $other['poster'] }}" alt="" width="320" height="180" loading="lazy"><span>{{ $other['title'] }}</span></a></li>
            @endforeach
        </ul>
        <p><a href="{{ $all }}">{{ $copy['all'] }}</a></p>
    </section>
@endsection
