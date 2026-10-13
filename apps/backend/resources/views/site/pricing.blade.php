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
                            <a href="?{{ http_build_query(array_filter(['period' => $period, 'currency' => $pricing['currency'], 'stores' => $pricing['extra_stores'] ?: null])) }}" @if ($period === $pricing['period']) aria-current="true" @endif>{{ \App\Support\Site\PricingTable::periodLabel($period, $locale) }}</a>
                        @endforeach
                    </nav>
                @endif
                @if (count($pricing['currencies']) > 1)
                    <nav aria-label="{{ __('site.pricing.currency_label') }}" class="site-switch">
                        @foreach ($pricing['currencies'] as $currency)
                            <a href="?{{ http_build_query(array_filter(['period' => $pricing['period'], 'currency' => $currency, 'stores' => $pricing['extra_stores'] ?: null])) }}" @if ($currency === $pricing['currency']) aria-current="true" @endif>{{ $currency }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
        @endif

        @if ($pricing['extra_stores_max'] > 0)
            <form method="get" class="site-stores" data-form="stores">
                <input type="hidden" name="period" value="{{ $pricing['period'] }}">
                <input type="hidden" name="currency" value="{{ $pricing['currency'] }}">
                <label for="extra-stores">{{ __('site.pricing.stores_label') }}</label>
                <input id="extra-stores" type="number" name="stores" min="0" max="{{ $pricing['extra_stores_max'] }}" value="{{ $pricing['extra_stores'] }}" inputmode="numeric">
                <button type="submit" class="site-button site-button-secondary">{{ __('site.pricing.stores_apply') }}</button>
            </form>
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
                        @if ($plan['with_extra_stores'])
                            <p class="site-total" data-total="{{ $plan['code'] }}">{{ trans_choice('site.pricing.with_stores', $plan['with_extra_stores']['count'], ['n' => $plan['with_extra_stores']['count']]) }} <strong>{{ $plan['with_extra_stores']['price'] }}</strong> <span>{{ $plan['per'] }}</span></p>
                            @if ($plan['with_extra_stores']['capped'])
                                <p class="site-small site-muted">{{ __('site.pricing.stores_capped', ['max' => $plan['extra_stores_max']]) }}</p>
                            @endif
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
                            <p class="site-actions"><a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}?plan={{ urlencode($plan['code']) }}">{{ __('site.pricing.choose') }}</a></p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
