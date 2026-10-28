@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <p class="site-muted site-small">{{ __('site.check_store.eyebrow') }}</p>
        <h1>{{ __('site.check_store.heading') }}</h1>
        <p class="site-lead">{{ __('site.check_store.lead') }}</p>

        <section class="site-note">
            <h2>{{ __('site.check_store.limits_title') }}</h2>
            <p>{{ __('site.check_store.limits_body') }}</p>
        </section>

        @if ($check === null)
            @if ($errors->any())
                <div class="site-errors" role="alert" tabindex="-1" id="form-errors">
                    <p>{{ __('site.check_store.errors_title') }}</p>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="post" action="{{ \App\Support\Site\SiteContent::url($locale, 'check-store') }}" class="site-form" data-form="public-storefront-check" novalidate>
                @csrf
                <div class="site-field">
                    <label for="url">{{ __('site.check_store.url') }}</label>
                    <input id="url" name="url" type="url" inputmode="url" autocomplete="url" maxlength="2048" required placeholder="https://shop.example" value="{{ old('url') }}" @error('url') aria-invalid="true" aria-describedby="check-store-url-error" @enderror>
                    @error('url')<p class="site-field-error" id="check-store-url-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="captcha_answer">{{ __('site.check_store.captcha') }}</label>
                    <div class="site-captcha-row">
                        <img src="{{ $captcha_url }}" alt="{{ __('site.check_store.captcha_alt') }}" width="156" height="54">
                        <input id="captcha_answer" name="captcha_answer" type="number" inputmode="numeric" min="0" max="99" required autocomplete="off" @error('captcha_answer') aria-invalid="true" aria-describedby="check-store-captcha-error" @enderror>
                    </div>
                    @error('captcha_answer')<p class="site-field-error" id="check-store-captcha-error">{{ $message }}</p>@enderror
                    <p class="site-muted site-small">{{ __('site.check_store.captcha_hint') }}</p>
                </div>
                <p class="site-muted site-small">{{ __('site.check_store.history_notice') }}</p>
                <p><button type="submit" class="site-button" data-loading-text="{{ __('site.check_store.loading_submit') }}">{{ __('site.check_store.submit') }}</button></p>
            </form>
        @else
            <div data-public-check-result data-status-url="{{ \App\Support\Site\SiteContent::url($locale, 'check-store/'.$result_token.'/status') }}" data-pending="{{ in_array($check->status, [\App\Models\PublicStorefrontCheck::STATUS_QUEUED, \App\Models\PublicStorefrontCheck::STATUS_RUNNING], true) ? '1' : '0' }}">
                @include('site.partials.check-store-result')
            </div>
        @endif

        @if ($check === null)
            @php($about = trans('site.check_store.about'))
            <div class="site-about" data-check-store-about>
                <h2>{{ $about['what_title'] }}</h2>
                <ul>
                    @foreach ($about['what'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>

                <h2>{{ $about['finds_title'] }}</h2>
                <div class="site-cards">
                    @foreach ($about['finds'] as [$cardTitle, $cardText])
                        <div class="site-card"><h3>{{ $cardTitle }}</h3><p>{{ $cardText }}</p></div>
                    @endforeach
                </div>

                <h2>{{ $about['compare_title'] }}</h2>
                <div class="site-table-wrap">
                    <table class="site-table">
                        <thead><tr>@foreach ($about['compare_head'] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($about['compare_rows'] as $row)
                                <tr><th scope="row">{{ $row[0] }}</th>@foreach (array_slice($row, 1) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <h2>{{ $about['limits_title'] }}</h2>
                <ul>
                    @foreach ($about['limits'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>

                <h2>{{ $about['faq_title'] }}</h2>
                @foreach ($about['faq'] as [$question, $answer])
                    <div class="site-faq-item"><h3>{{ $question }}</h3><p class="site-muted">{{ $answer }}</p></div>
                @endforeach
            </div>
        @endif
    </section>
    <script>
        (() => {
            const form = document.querySelector('[data-form="public-storefront-check"]');
            if (form) {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('button[type="submit"]');
                    if (!button) {
                        return;
                    }
                    button.disabled = true;
                    button.classList.add('site-button-loading');
                    button.textContent = button.dataset.loadingText || button.textContent;
                });
            }

            const result = document.querySelector('[data-public-check-result]');
            if (!result || result.dataset.pending !== '1') {
                return;
            }

            const poll = async () => {
                try {
                    const response = await fetch(result.dataset.statusUrl, {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store',
                    });

                    if (!response.ok) {
                        window.setTimeout(poll, 5000);
                        return;
                    }

                    const payload = await response.json();
                    if (typeof payload.html === 'string') {
                        result.innerHTML = payload.html;
                    }

                    if (!payload.terminal) {
                        window.setTimeout(poll, Number(payload.poll_after_ms) || 2500);
                    }
                } catch {
                    window.setTimeout(poll, 5000);
                }
            };

            window.setTimeout(poll, 1200);
        })();
    </script>
@endsection
