@extends('site.layout')

@section('content')
    <section class="site-wrap site-section">
        <h1>{{ $title }}</h1>
        @if ($body !== null)
            <div class="site-content">{!! $body !!}</div>
        @else
            <p class="site-lead">{{ __('site.pricing.lead') }}</p>
        @endif

        @if ($pricing['test_mode'] && $pricing['plans'] !== [])
            <p class="site-note" role="note">{{ __('site.pricing.test_mode') }}</p>
        @endif

        @if (count($pricing['periods']) > 1 || count($pricing['currencies']) > 1)
            <div class="site-switches">
                @if (count($pricing['periods']) > 1)
                    <nav aria-label="{{ __('site.pricing.period_label') }}" class="site-switch">
                        @foreach ($pricing['periods'] as $period)
                            <a href="?{{ http_build_query(['period' => $period, 'currency' => $pricing['currency']]) }}" @if ($period === $pricing['period']) aria-current="true" @endif>{{ \App\Support\Site\PricingTable::periodLabel($period, $locale) }}</a>
                        @endforeach
                    </nav>
                @endif
                @if (count($pricing['currencies']) > 1)
                    <nav aria-label="{{ __('site.pricing.currency_label') }}" class="site-switch">
                        @foreach ($pricing['currencies'] as $currency)
                            <a href="?{{ http_build_query(['period' => $pricing['period'], 'currency' => $currency]) }}" @if ($currency === $pricing['currency']) aria-current="true" @endif>{{ $currency }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
        @endif

        @if ($pricing['plans'] === [])
            <p class="site-note">{{ __('site.pricing.empty') }} <a href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.nav.contact') }}</a></p>
        @else
            <div class="site-plans">
                @foreach ($pricing['plans'] as $plan)
                    <article class="site-plan" data-plan="{{ $plan['code'] }}">
                        <h2>{{ $plan['name'] }}</h2>
                        @if ($plan['placeholder'])
                            <p class="site-badge site-badge-muted">{{ __('site.pricing.placeholder') }}</p>
                        @endif
                        @if ($plan['description'])
                            <p class="site-muted">{{ $plan['description'] }}</p>
                        @endif
                        @if ($plan['individual'])
                            <p class="site-price"><strong>{{ __('site.pricing.individual') }}</strong></p>
                        @else
                            <p class="site-price"><strong>{{ $plan['price'] }}</strong> <span>{{ $plan['per'] }}</span></p>
                        @endif
                        @if ($plan['monthly'])
                            <p class="site-muted">{{ __('site.pricing.monthly', ['amount' => $plan['monthly']]) }}</p>
                        @endif
                        @if ($plan['saving'])
                            <p class="site-badge">{{ __('site.pricing.saving', ['n' => $plan['saving']]) }}</p>
                        @endif
                        @if ($plan['tax'])
                            <p class="site-muted site-small">{{ $plan['tax'] }}</p>
                        @endif
                        @if ($plan['extra_store'])
                            <p class="site-small">{{ __('site.pricing.extra_store', ['amount' => $plan['extra_store']]) }}</p>
                        @endif
                        @if ($plan['faster_checks'])
                            <p class="site-small">{{ __('site.pricing.faster_checks', ['minutes' => $plan['faster_checks']['minutes'], 'amount' => $plan['faster_checks']['price']]) }}</p>
                        @endif
                        @if ($plan['trial_days'] > 0 && ! $plan['individual'])
                            <p class="site-small">{{ trans_choice('site.pricing.trial', $plan['trial_days'], ['n' => $plan['trial_days']]) }}</p>
                        @endif
                        @if ($plan['features'] !== [])
                            <h3>{{ __('site.pricing.features_title') }}</h3>
                            <ul class="site-list">
                                @foreach ($plan['features'] as $feature)
                                    <li>{{ $feature }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($plan['limits'] !== [])
                            <h3>{{ __('site.pricing.limits_title') }}</h3>
                            <dl class="site-limits">
                                @foreach ($plan['limits'] as $limit)
                                    <div><dt>{{ $limit['label'] }}</dt><dd>{{ $limit['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        @if ($plan['individual'])
                            <p class="site-actions"><a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.pricing.contact_us') }}</a></p>
                        @else
                            <p class="site-actions"><a class="site-button" href="/app/settings/billing?plan={{ urlencode($plan['code']) }}">{{ __('site.pricing.choose') }}</a></p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
