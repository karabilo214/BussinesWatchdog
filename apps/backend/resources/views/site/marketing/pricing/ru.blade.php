<div class="bw-page" lang="ru"><section class="bw-pricing-hero"><p class="bw-eyebrow">Тарифы Business Watchdog</p><h1>Понятная подписка.<br><span class="bw-accent">Под объём вашего магазина.</span></h1><p class="bw-lead">Выберите пакет по числу заказов, количеству магазинов и частоте браузерных проверок. Фиксированная цена за период — без процента от оборота.</p><div class="bw-meta"><span>Пробный период — по условиям выбранного тарифа</span><span>Отмена продления — в кабинете</span></div></section>
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

<p class="bw-inline-note">Калькулятор показывает действующие опубликованные цены. Выбор перейдёт в кабинет, где перед оплатой вы подтвердите итоговую сумму.</p>@if ($pricing['plans'] === [])
    <p class="bw-empty">{{ __('site.pricing.empty') }} <a class="bw-textlink" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.nav.contact') }}</a></p>
@else
<div class="bw-plans">
@foreach ($pricing['plans'] as $plan)
    <article class="bw-plan {{ $plan['code'] === 'business' ? 'bw-plan-featured' : '' }}" data-plan="{{ $plan['code'] }}">
        <p class="bw-plan-label">{{ $plan['code'] === 'business' ? 'Для растущего магазина' : $plan['name'] }}</p>
        <h2>{{ $plan['name'] }}</h2>
        @if ($plan['placeholder'])<p class="bw-pill">{{ __('site.pricing.placeholder') }}</p>@endif
        @if ($plan['description'])<p class="bw-plan-intro">{{ $plan['description'] }}</p>@endif
        @if ($plan['individual'])
            <p class="bw-price bw-price-custom">{{ __('site.pricing.individual') }}</p>
        @else
            <p class="bw-price"><strong>{{ $plan['with_extra_stores']['price'] ?? $plan['price'] }}</strong><span>{{ $plan['per'] }}</span></p>
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
            <a class="bw-btn {{ $plan['code'] === 'business' ? '' : 'bw-btn-outline' }}" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}?{{ http_build_query(['plan' => $plan['code'], 'price' => $plan['price_id'], 'stores' => $plan['with_extra_stores']['count'] ?? 0]) }}">{{ __('site.pricing.choose') }}</a>
            @if ($plan['trial_days'] > 0)<p class="bw-trial">{{ trans_choice('site.pricing.trial', $plan['trial_days'], ['n' => $plan['trial_days']]) }}</p>@endif
        @endif
        </div>
    </article>
@endforeach
</div>
@endif
<p class="bw-inline-note">Лимит заказов относится к каждому магазину. Число включённых магазинов указано в тарифе; дополнительные оплачиваются отдельно.</p><section class="bw-section"><div class="bw-section-head"><div><h2>Основной контроль — во всех трёх пакетах</h2><p class="bw-copy">Пакеты отличаются объёмом, интервалом проверок и глубиной истории. Финансовая сверка требует отдельного подключения поддерживаемого платёжного источника.</p></div></div><div class="bw-grid-3"><article class="bw-card"><span class="bw-number">01</span><h3>Браузерные проверки</h3><p>Настроенный путь до доступного платёжного шага, без подтверждения покупки.</p></article><article class="bw-card"><span class="bw-number">02</span><h3>Контроль расхождений</h3><p>Сопоставление доступных заказов, списаний и возвратов по подключённым источникам.</p></article><article class="bw-card"><span class="bw-number">03</span><h3>Кабинет и уведомления</h3><p>Результаты, инциденты и уведомления с учётом доступности данных.</p></article></div></section><section class="bw-section" id="questions"><div class="bw-section-head"><h2>Как устроены оплата и лимиты</h2></div><div class="bw-faq"><article><h3>Это комиссия с продаж?</h3><p>Нет. Вы оплачиваете пакет за период, без процента от оборота. Число заказов определяет подходящий пакет, а не отдельное списание за каждую продажу.</p></article><article><h3>Как считается объём заказов?</h3><p>Каждый новый реальный заказ учитывается один раз. Повторная синхронизация, смена статуса и возвраты не добавляют новые заказы. Первоначальный импорт истории не расходует этот объём. Для оценки используется среднее за три завершённых месяца.</p></article><article><h3>Что будет при превышении объёма?</h3><p>При превышении сервис предупредит и предоставит срок для перехода по условиям выбранного пакета. Автоматической доплаты нет. После этого срока мониторинг организации может быть приостановлен.</p></article><article><h3>Что такое события?</h3><p>Это технические сообщения о состоянии магазина и заказов, а не число покупок. Один заказ может породить несколько событий. Лимит событий показан отдельно от лимита заказов.</p></article><article><h3>Как закончится пробный период?</h3><p>Платёжная карта не нужна. Пробный период завершается сам; платная подписка начинается только после покупки. Пробный период доступен один раз для магазина.</p></article><article><h3>Как отменить подписку?</h3><p>Отключите продление в кабинете. Оплаченный доступ сохраняется до конца периода. Порядок возвратов описан в политике подписки и возвратов.</p></article></div></section><section class="bw-section"><div class="bw-cta"><h2>Не уверены, какой пакет подойдёт?</h2><p class="bw-copy">Расскажите о платформе, числе магазинов и потоке заказов. Поможем проверить совместимость и выбрать объём без лишних подключений.</p><div class="bw-actions"><a class="bw-btn" href="/ru/contact">Уточнить условия</a><a class="bw-btn bw-btn-outline" href="/ru/check-store">Проверить магазин по ссылке</a></div><p class="bw-inline-note"><a class="bw-textlink" href="/ru/refund-policy">Политика подписки и возвратов</a></p></div></section></div>
