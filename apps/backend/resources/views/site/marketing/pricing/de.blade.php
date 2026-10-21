<div class="bw-page" lang="de"><section class="bw-pricing-hero"><p class="bw-eyebrow">Business Watchdog Tarife</p><h1>Ein klares Abonnement.<br><span class="bw-accent">Passend zu Ihrem Shop.</span></h1><p class="bw-lead">Wählen Sie nach Bestellvolumen, Anzahl der Shops und Häufigkeit der Browser-Prüfungen. Ein fester Preis pro Abrechnungszeitraum, ohne Umsatzbeteiligung.</p><div class="bw-meta"><span>Testzeit gemäß den Bedingungen des gewählten Tarifs</span><span>Verlängerung im Dashboard kündbar</span></div></section>
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

<p class="bw-inline-note">Der Rechner zeigt einen Richtpreis. Zusätzliche Shops, Abrechnungszeitraum und Währung wählen Sie vor der Zahlung erneut im Dashboard.</p>@if ($pricing['plans'] === [])
    <p class="bw-empty">{{ __('site.pricing.empty') }} <a class="bw-textlink" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.nav.contact') }}</a></p>
@else
<div class="bw-plans">
@foreach ($pricing['plans'] as $plan)
    <article class="bw-plan {{ $plan['code'] === 'business' ? 'bw-plan-featured' : '' }}" data-plan="{{ $plan['code'] }}">
        <p class="bw-plan-label">{{ $plan['code'] === 'business' ? 'Für wachsende Shops' : $plan['name'] }}</p>
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
<p class="bw-inline-note">Das Bestelllimit gilt je Shop. Enthaltene Shops sind im Tarif angegeben; zusätzliche Shops werden separat berechnet.</p><section class="bw-section"><div class="bw-section-head"><div><h2>Grundlegendes Monitoring in jedem Standardtarif</h2><p class="bw-copy">Die Pakete unterscheiden sich in Volumen, Prüfintervallen und Verlauf. Für den finanziellen Abgleich ist die separate Anbindung eines unterstützten Zahlungsanbieters nötig.</p></div></div><div class="bw-grid-3"><article class="bw-card"><span class="bw-number">01</span><h3>Browser-Prüfungen</h3><p>Ein eingerichteter Weg bis zum erreichbaren Zahlungsschritt, ohne Kaufabschluss.</p></article><article class="bw-card"><span class="bw-number">02</span><h3>Abweichungen erkennen</h3><p>Verfügbare Bestellungen, Belastungen und Erstattungen aus verbundenen Quellen abgleichen.</p></article><article class="bw-card"><span class="bw-number">03</span><h3>Dashboard und Hinweise</h3><p>Ergebnisse, Vorfälle und Hinweise unter Berücksichtigung der Datenverfügbarkeit.</p></article></div></section><section class="bw-section" id="questions"><div class="bw-section-head"><h2>Abrechnung und Limits verständlich erklärt</h2></div><div class="bw-faq"><article><h3>Ist das eine Gebühr pro Verkauf?</h3><p>Nein. Sie zahlen einen Paketpreis pro Abrechnungszeitraum ohne Umsatzbeteiligung. Das Bestellvolumen bestimmt das passende Paket, keine einzelne Gebühr pro Verkauf.</p></article><article><h3>Wie wird das Bestellvolumen gezählt?</h3><p>Jede neue echte Bestellung zählt einmal. Erneute Synchronisierung, Statuswechsel und Erstattungen zählen nicht zusätzlich. Der erste Import historischer Daten ist ausgenommen. Bewertet wird der Durchschnitt von drei abgeschlossenen Monaten.</p></article><article><h3>Was passiert bei zu hohem Bestellvolumen?</h3><p>Sie erhalten einen Hinweis und eine Frist gemäß den Bedingungen Ihres Pakets. Es gibt keine automatische Nachberechnung. Danach kann das Monitoring der Organisation pausiert werden.</p></article><article><h3>Was sind Ereignisse?</h3><p>Technische Meldungen über Shop- und Bestellzustände, keine Käufe. Eine Bestellung kann mehrere Ereignisse auslösen. Ereignis- und Bestelllimit werden getrennt ausgewiesen.</p></article><article><h3>Was passiert nach der Testzeit?</h3><p>Eine Karte ist nicht nötig. Die Testzeit endet von selbst; ein kostenpflichtiges Abonnement beginnt erst mit dem Kauf. Die Testzeit ist einmal je Shop verfügbar.</p></article><article><h3>Wie kündige ich?</h3><p>Deaktivieren Sie die Verlängerung im Dashboard. Der bezahlte Zugang bleibt bis zum Ende des Zeitraums erhalten. Erstattungen regelt unsere Abonnement- und Erstattungsrichtlinie.</p></article></div></section><section class="bw-section"><div class="bw-cta"><h2>Unsicher, welcher Tarif passt?</h2><p class="bw-copy">Nennen Sie uns Plattform, Shopanzahl und Bestellvolumen. Wir helfen bei der Kompatibilitätsprüfung und der Wahl eines passenden Pakets.</p><div class="bw-actions"><a class="bw-btn" href="/de/contact">Anbindung besprechen</a><a class="bw-btn bw-btn-outline" href="/de/check-store">Shop per URL prüfen</a></div><p class="bw-inline-note"><a class="bw-textlink" href="/de/refund-policy">Abonnement- und Erstattungsbedingungen</a></p></div></section></div>
