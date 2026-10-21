<div class="bw-page" lang="en"><section class="bw-pricing-hero"><p class="bw-eyebrow">Business Watchdog pricing</p><h1>A clear subscription.<br><span class="bw-accent">Sized for your store.</span></h1><p class="bw-lead">Choose by order volume, store count and browser check frequency. A fixed price per billing period, without a percentage of your revenue.</p><div class="bw-meta"><span>Trial availability depends on the selected plan</span><span>Cancel renewal in your dashboard</span></div></section>
        @if ($pricing['test_mode'] && $pricing['plans'] !== [])
            <p class="bw-empty" role="note">{{ __('site.pricing.test_mode') }}</p>
        @endif

        @if (count($pricing['periods']) > 1 || count($pricing['currencies']) > 1)
            <div class="bw-switches">
                @if (count($pricing['periods']) > 1)
                    <nav aria-label="{{ __('site.pricing.period_label') }}" class="bw-switch">
                        @foreach ($pricing['periods'] as $period)
                            <a href="?{{ http_build_query(array_filter(['period' => $period, 'currency' => $pricing['currency'], 'stores' => $pricing['extra_stores'] ?: null])) }}" @if ($period === $pricing['period']) aria-current="true" @endif>{{ \App\Support\Site\PricingTable::periodLabel($period, $locale) }}</a>
                        @endforeach
                    </nav>
                @endif
                @if (count($pricing['currencies']) > 1)
                    <nav aria-label="{{ __('site.pricing.currency_label') }}" class="bw-switch">
                        @foreach ($pricing['currencies'] as $currency)
                            <a href="?{{ http_build_query(array_filter(['period' => $pricing['period'], 'currency' => $currency, 'stores' => $pricing['extra_stores'] ?: null])) }}" @if ($currency === $pricing['currency']) aria-current="true" @endif>{{ $currency }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
        @endif

        @if ($pricing['extra_stores_max'] > 0)
            <form method="get" class="bw-calculator" data-form="stores">
                <input type="hidden" name="period" value="{{ $pricing['period'] }}">
                <input type="hidden" name="currency" value="{{ $pricing['currency'] }}">
                <label for="extra-stores">{{ __('site.pricing.stores_label') }}</label>
                <input id="extra-stores" type="number" name="stores" min="0" max="{{ $pricing['extra_stores_max'] }}" value="{{ $pricing['extra_stores'] }}" inputmode="numeric">
                <button type="submit" class="bw-btn">{{ __('site.pricing.stores_apply') }}</button>
            </form>
        @endif

<p class="bw-inline-note">The calculator provides an estimate. Select additional stores, billing period and currency again in your dashboard before payment.</p>@if ($pricing['plans'] === [])
    <p class="bw-empty">{{ __('site.pricing.empty') }} <a class="bw-textlink" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.nav.contact') }}</a></p>
@else
<div class="bw-plans">
@foreach ($pricing['plans'] as $plan)
    <article class="bw-plan {{ $plan['code'] === 'business' ? 'bw-plan-featured' : '' }}" data-plan="{{ $plan['code'] }}">
        <p class="bw-plan-label">{{ $plan['code'] === 'business' ? 'For growing stores' : $plan['name'] }}</p>
        <h2>{{ $plan['name'] }}</h2>
        @if ($plan['placeholder'])<p class="bw-pill">{{ __('site.pricing.placeholder') }}</p>@endif
        @if ($plan['description'])<p class="bw-plan-intro">{{ $plan['description'] }}</p>@endif
        @if ($plan['individual'])
            <p class="bw-price bw-price-custom">{{ __('site.pricing.individual') }}</p>
        @else
            <p class="bw-price"><strong>{{ $plan['price'] }}</strong><span>{{ $plan['per'] }}</span></p>
        @endif
        @if ($plan['monthly'])<p class="bw-small">{{ __('site.pricing.monthly', ['amount' => $plan['monthly']]) }}</p>@endif
        @if ($plan['saving'])<p class="bw-pill">{{ __('site.pricing.saving', ['n' => $plan['saving']]) }}</p>@endif
        @if ($plan['tax'])<p class="bw-tax">{{ $plan['tax'] }}</p>@endif
        @if ($plan['with_extra_stores'])
            <p class="bw-total" data-total="{{ $plan['code'] }}">{{ trans_choice('site.pricing.with_stores', $plan['with_extra_stores']['count'], ['n' => $plan['with_extra_stores']['count']]) }} <strong>{{ $plan['with_extra_stores']['price'] }}</strong> {{ $plan['per'] }}</p>
            @if ($plan['with_extra_stores']['capped'])<p class="bw-small">{{ __('site.pricing.stores_capped', ['max' => $plan['extra_stores_max']]) }}</p>@endif
        @endif
        @if ($plan['limits'] !== [])
            <dl>
            @foreach ($plan['limits'] as $limit)
                <div><dt>{{ $limit['label'] }}</dt><dd>{{ $limit['value'] }}</dd></div>
            @endforeach
            </dl>
        @endif
        @if ($plan['features'] !== [])
            <ul>
            @foreach ($plan['features'] as $feature)<li>{{ $feature }}</li>@endforeach
            </ul>
        @endif
        @if ($plan['extra_store'])<p class="bw-small">{{ __('site.pricing.extra_store', ['amount' => $plan['extra_store']]) }} {{ $plan['per'] }}</p>@endif
        @if ($plan['faster_checks'])<p class="bw-small">{{ __('site.pricing.faster_checks', ['minutes' => $plan['faster_checks']['minutes'], 'amount' => $plan['faster_checks']['price']]) }}</p>@endif
        <div class="bw-plan-cta">
        @if ($plan['individual'])
            <a class="bw-btn bw-btn-outline" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.pricing.contact_us') }}</a>
        @else
            <a class="bw-btn {{ $plan['code'] === 'business' ? '' : 'bw-btn-outline' }}" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}?plan={{ urlencode($plan['code']) }}">{{ __('site.pricing.choose') }}</a>
            @if ($plan['trial_days'] > 0)<p class="bw-trial">{{ trans_choice('site.pricing.trial', $plan['trial_days'], ['n' => $plan['trial_days']]) }}</p>@endif
        @endif
        </div>
    </article>
@endforeach
</div>
@endif
<p class="bw-inline-note">Order limits apply to each store. Each plan lists its included stores; additional stores are charged separately.</p><section class="bw-section"><div class="bw-section-head"><div><h2>Core monitoring in every standard plan</h2><p class="bw-copy">Plans differ in volume, check intervals and history. Financial reconciliation needs a separate connection to a supported payment provider.</p></div></div><div class="bw-grid-3"><article class="bw-card"><span class="bw-number">01</span><h3>Browser checks</h3><p>A configured journey to the available payment step, without confirming a purchase.</p></article><article class="bw-card"><span class="bw-number">02</span><h3>Discrepancy monitoring</h3><p>Compare available orders, charges and refunds across connected sources.</p></article><article class="bw-card"><span class="bw-number">03</span><h3>Dashboard and alerts</h3><p>Results, incidents and alerts that account for data availability.</p></article></div></section><section class="bw-section" id="questions"><div class="bw-section-head"><h2>Billing and limits, explained</h2></div><div class="bw-faq"><article><h3>Is this a fee on each sale?</h3><p>No. You pay for a package per billing period, with no revenue percentage. Order volume determines the right package, not a separate charge for every sale.</p></article><article><h3>How is order volume counted?</h3><p>Each new real order counts once. Repeated syncs, status updates and refunds do not add new orders. The initial history import is excluded. Volume is assessed using the average of three complete months.</p></article><article><h3>What if I exceed the order allowance?</h3><p>You receive a warning and a grace period under the selected package’s terms. There is no automatic overage charge. Monitoring for the organisation may be paused afterwards.</p></article><article><h3>What are events?</h3><p>Technical messages about store and order state, not purchases. A single order can produce several events. The event allowance is separate from the order allowance.</p></article><article><h3>What happens after the trial?</h3><p>No card is needed. The trial ends automatically; a paid subscription starts only when you purchase a plan. A trial is available once per store.</p></article><article><h3>How do I cancel?</h3><p>Turn off renewal in your dashboard. Paid access continues until the end of the billing period. See the subscription and refund policy for refund terms.</p></article></div></section><section class="bw-section"><div class="bw-cta"><h2>Not sure which plan fits?</h2><p class="bw-copy">Tell us your platform, number of stores and order volume. We can check compatibility and help you choose a suitable package.</p><div class="bw-actions"><a class="bw-btn" href="/en/contact">Ask about your setup</a><a class="bw-btn bw-btn-outline" href="/en/check-store">Check your store by URL</a></div><p class="bw-inline-note"><a class="bw-textlink" href="/en/refund-policy">Subscription and refund policy</a></p></div></section></div>
