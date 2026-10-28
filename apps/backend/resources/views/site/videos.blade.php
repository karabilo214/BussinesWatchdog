@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <p class="site-muted site-small">{{ __('site.videos.eyebrow') }}</p>
        <h1>{{ __('site.videos.heading') }}</h1>
        <p class="site-lead">{{ __('site.videos.lead') }}</p>
        @if ($videoLanguageNote)
            <p class="site-muted site-small">{{ __('site.videos.language_note') }}</p>
        @endif

        @foreach ($videos as $video)
            <section class="site-video" id="{{ $video['key'] }}" data-video="{{ $video['key'] }}">
                <h2>{{ __('site.videos.items.'.$video['key'].'.title') }}</h2>
                <p>{{ __('site.videos.items.'.$video['key'].'.text') }}</p>
                <video controls playsinline preload="none" width="1280" height="720" poster="{{ $video['poster'] }}" lang="{{ $video['language'] }}">
                    <source src="{{ $video['src'] }}" type="video/mp4">
                    <a href="{{ $video['src'] }}">{{ __('site.videos.download') }}</a>
                </video>
            </section>
        @endforeach

        <section class="site-note">
            <h2>{{ __('site.videos.honest_title') }}</h2>
            <p>{{ __('site.videos.honest_body') }}</p>
        </section>

        <p class="site-stores">
            <a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ __('site.videos.cta_trial') }}</a>
            <a class="site-button site-button-secondary" href="{{ \App\Support\Site\SiteContent::url($locale, 'check-store') }}">{{ __('site.videos.cta_check') }}</a>
        </p>
    </section>
@endsection
