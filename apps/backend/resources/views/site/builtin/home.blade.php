<section class="hero">
<p class="eyebrow">{{ __('site.home.eyebrow') }}</p>
<h1>{{ __('site.home.heading') }}</h1>
<p class="lead">{{ __('site.home.lead') }}</p>
<p class="actions"><a class="button" href="{{ \App\Support\Site\SiteContent::url($locale, 'pricing') }}">{{ __('site.home.cta_pricing') }}</a> <a class="button button-secondary" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ __('site.nav.sign_up') }}</a></p>
</section>
<section class="band">
<h2>{{ __('site.home.features_title') }}</h2>
<div class="grid-2">
@foreach (trans('site.home.features') as [$heading, $text])
<div class="feature">
<h3>{{ $heading }}</h3>
<p>{{ $text }}</p>
</div>
@endforeach
</div>
</section>
<section>
<h2>{{ __('site.home.limits_title') }}</h2>
<p class="muted">{{ __('site.home.limits_body') }}</p>
</section>
