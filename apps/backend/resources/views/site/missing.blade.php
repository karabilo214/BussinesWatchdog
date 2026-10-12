@extends('site.layout')

@section('content')
    <section class="site-wrap site-section">
        <h1>{{ __('site.missing.title') }}</h1>
        <p class="site-muted">{{ __('site.missing.body') }}</p>
        <p class="site-actions"><a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale) }}">{{ __('site.missing.back') }}</a></p>
    </section>
@endsection
