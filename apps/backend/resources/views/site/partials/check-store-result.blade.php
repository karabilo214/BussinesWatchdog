<section class="site-success" data-result-card="public-storefront-check" aria-live="polite">
    @if (in_array($check->status, [\App\Models\PublicStorefrontCheck::STATUS_QUEUED, \App\Models\PublicStorefrontCheck::STATUS_RUNNING], true))
        <span class="site-spinner" aria-hidden="true"></span>
    @endif
    <p class="site-muted site-small">{{ $check->normalized_origin }}</p>
    <h2>{{ __('site.check_store.verdicts.'.($check->verdict ?? 'queued')) }}</h2>
    <p>{{ __('site.check_store.statuses.'.$check->status) }}</p>
    @if (! empty($check->diagnostics['detected_platform']))
        <p>{{ __('site.check_store.detected_platform', ['platform' => __('site.check_store.platforms.'.$check->diagnostics['detected_platform'])]) }}</p>
    @endif
    @if (! empty($check->error_code))
        <p>{{ __('site.check_store.errors.'.$check->error_code) }}</p>
    @endif
    <p class="site-muted site-small">{{ __('site.check_store.result_ttl') }}</p>
</section>

@php
    $audit = $check->diagnostics['public_audit'] ?? null;
    $errors = collect($check->diagnostics['relevant_errors'] ?? [])->filter(function ($error) {
        $type = $error['type'] ?? '';

        if (($error['party'] ?? '') === 'third' && in_array($type, ['request_failed', 'http_5xx'], true)) {
            return false;
        }

        return in_array($type, ['console_error', 'console_warning', 'js_error', 'request_failed', 'http_5xx'], true);
    })->take(8);
@endphp

@if (is_array($audit))
    <section class="site-section">
        <h2>{{ __('site.check_store.audit_title') }}</h2>
        <div class="site-plans">
            <article class="site-plan">
                <p class="site-muted site-small">{{ __('site.check_store.audit_inventory') }}</p>
                <h3>{{ __('site.check_store.audit_found') }}</h3>
                <p>{{ __('site.check_store.audit_counts', ['categories' => (int) ($audit['categories_found'] ?? 0), 'products' => (int) ($audit['products_found'] ?? 0)]) }}</p>
            </article>
            <article class="site-plan">
                <p class="site-muted site-small">{{ __('site.check_store.audit_cart') }}</p>
                <h3>{{ __('site.check_store.audit_cart_results.'.($audit['cart_result'] ?? 'not_tested')) }}</h3>
                <p>{{ __('site.check_store.audit_products_tested', ['count' => (int) ($audit['products_tested'] ?? 0)]) }}</p>
            </article>
            <article class="site-plan">
                <p class="site-muted site-small">{{ __('site.check_store.audit_checkout') }}</p>
                <h3>{{ __('site.check_store.audit_checkout_results.'.($audit['checkout_result'] ?? 'not_tested')) }}</h3>
                <p>{{ __('site.check_store.audit_checkout_note') }}</p>
            </article>
        </div>

        @if (! empty($audit['sampled_products']))
            <h2>{{ __('site.check_store.audit_products_title') }}</h2>
            <div class="site-plans">
                @foreach (array_slice($audit['sampled_products'], 0, 5) as $product)
                    <article class="site-plan">
                        <p class="site-muted site-small">{{ $product['url'] ?? '' }}</p>
                        <h3>{{ __('site.check_store.audit_add_to_cart_results.'.($product['add_to_cart'] ?? 'not_tested')) }}</h3>
                        <p>{{ ! empty($product['cart_verified']) ? __('site.check_store.audit_cart_verified') : __('site.check_store.audit_cart_not_verified') }}</p>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($errors->isNotEmpty())
            <h2>{{ __('site.check_store.audit_errors_title') }}</h2>
            <div class="site-plans">
                @foreach ($errors as $error)
                    <article class="site-plan">
                        <p class="site-muted site-small">{{ $error['type'] ?? '' }}</p>
                        <h3>{{ $error['message_code'] ?? ($error['status'] ?? '') }}</h3>
                        @if (! empty($error['path']))
                            <p>{{ $error['origin'] ?? '' }}{{ $error['path'] }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <section class="site-note">
            <h2>{{ __('site.check_store.audit_upgrade_title') }}</h2>
            <p>{{ __('site.check_store.audit_upgrade_body') }}</p>
        </section>
    </section>
@endif

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
