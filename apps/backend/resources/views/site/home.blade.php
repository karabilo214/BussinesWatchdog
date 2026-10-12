@extends('site.layout')

@section('content')
    @if ($body !== null)
        <div class="site-wrap site-content site-content-wide">{!! $body !!}</div>
    @else
        <section class="site-wrap site-hero">
            <p class="site-eyebrow">{{ __('site.home.eyebrow') }}</p>
            <h1>{{ __('site.home.heading') }}</h1>
            <p class="site-lead">{{ __('site.home.lead') }}</p>
            <p class="site-actions">
                <a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'pricing') }}">{{ __('site.home.cta_pricing') }}</a>
                <a class="site-button site-button-secondary" href="/app/login">{{ __('site.nav.sign_in') }}</a>
            </p>
        </section>
        <section class="site-band">
            <div class="site-wrap">
                <h2>{{ __('site.home.features_title') }}</h2>
                <div class="site-grid-2">
                    @foreach (trans('site.home.features') as [$heading, $text])
                        <article class="site-feature">
                            <h3>{{ $heading }}</h3>
                            <p>{{ $text }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
        <section class="site-wrap site-section">
            <h2>{{ __('site.home.limits_title') }}</h2>
            <p class="site-muted">{{ __('site.home.limits_body') }}</p>
        </section>
    @endif
@endsection
