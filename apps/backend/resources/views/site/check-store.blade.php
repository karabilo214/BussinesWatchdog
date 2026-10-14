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
                <p><button type="submit" class="site-button">{{ __('site.check_store.submit') }}</button></p>
            </form>
        @else
            <section class="site-success" data-result="public-storefront-check">
                <p class="site-muted site-small">{{ $check->normalized_origin }}</p>
                <h2>{{ __('site.check_store.verdicts.'.($check->verdict ?? 'queued')) }}</h2>
                <p>{{ __('site.check_store.statuses.'.$check->status) }}</p>
                @if (! empty($check->diagnostics['detected_platform']))
                    <p>{{ __('site.check_store.detected_platform', ['platform' => __('site.check_store.platforms.'.$check->diagnostics['detected_platform'])]) }}</p>
                @endif
                <p class="site-muted site-small">{{ __('site.check_store.result_ttl') }}</p>
            </section>

            @if ($check->steps !== [])
                <section class="site-section">
                    <h2>{{ __('site.check_store.steps_title') }}</h2>
                    <div class="site-plans">
                        @foreach ($check->steps as $step)
                            <article class="site-plan">
                                <p class="site-muted site-small">{{ $step['code'] ?? '' }}</p>
                                <h3>{{ __('site.check_store.step_statuses.'.($step['status'] ?? 'inconclusive')) }}</h3>
                                @if (! empty($step['error_code']))
                                    <p>{{ __('site.check_store.errors.'.$step['error_code']) }}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($artifacts->isNotEmpty())
                <section class="site-section">
                    <h2>{{ __('site.check_store.screenshots_title') }}</h2>
                    <div class="site-plans">
                        @foreach ($artifacts as $artifact)
                            <figure class="site-plan">
                                <img src="{{ \App\Support\Site\SiteContent::url($locale, 'check-store/'.$result_token.'/artifacts/'.$artifact->id) }}" alt="{{ __('site.check_store.screenshot_alt') }}">
                                <figcaption class="site-muted site-small">{{ __('site.check_store.screenshot_caption') }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </section>
@endsection
