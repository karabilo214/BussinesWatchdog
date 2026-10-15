<section class="site-success" data-result-card="public-storefront-check" aria-live="polite">
    @if (in_array($check->status, [\App\Models\PublicStorefrontCheck::STATUS_QUEUED, \App\Models\PublicStorefrontCheck::STATUS_RUNNING], true))
        <span class="site-spinner" aria-hidden="true"></span>
    @endif
    <p class="site-muted site-small">{{ $check->normalized_origin }}</p>
    @if (($check->diagnostics['public_audit']['checkout_result'] ?? null) === 'unsupported_platform')
        <h2>{{ __('site.check_store.audit_unsupported_title') }}</h2>
        <p>{{ __('site.check_store.audit_unsupported_status') }}</p>
    @else
        <h2>{{ __('site.check_store.verdicts.'.($check->verdict ?? 'queued')) }}</h2>
        <p>{{ __('site.check_store.statuses.'.$check->status) }}</p>
    @endif
    @if (! empty($check->diagnostics['detected_platform']))
        <p>{{ __('site.check_store.detected_platform', ['platform' => __('site.check_store.platforms.'.$check->diagnostics['detected_platform'])]) }}</p>
    @endif
    @if (! empty($check->error_code) && !(($check->diagnostics['public_audit']['checkout_result'] ?? null) === 'unsupported_platform' && $check->error_code === 'adapter_unsupported'))
        <p>{{ __('site.check_store.errors.'.$check->error_code) }}</p>
    @endif
    <p class="site-muted site-small">{{ __('site.check_store.result_ttl') }}</p>
</section>

@php
    $audit = $check->diagnostics['public_audit'] ?? null;
    $errors = collect($check->diagnostics['relevant_errors'] ?? [])->filter(function ($error) {
        $type = $error['type'] ?? '';
        $message = $error['message_code'] ?? '';

        if (($error['party'] ?? 'first') !== 'first' || in_array($message, ['ERR_ABORTED', 'ERR_BLOCKED_BY_CLIENT'], true)) {
            return false;
        }

        if (in_array($type, ['console_error', 'console_warning'], true) && str_starts_with($message, 'Failed to load resource:')) {
            return false;
        }

        return in_array($type, ['console_error', 'console_warning', 'js_error', 'request_failed', 'http_4xx_resource', 'http_5xx'], true);
    });
    $errorCount = (int) ($check->diagnostics['public_error_count'] ?? $errors->sum(fn ($error) => max(1, (int) ($error['count'] ?? 1))));
    $checkoutReached = in_array($audit['checkout_result'] ?? null, ['customer_details_reached', 'login_or_registration_reached'], true);
    $upgradeFinding = match (true) {
        $checkoutReached => 'checkout_reached',
        ($audit['cart_result'] ?? null) === 'product_present' => 'cart_reached',
        ($audit['checkout_result'] ?? null) === 'unsupported_platform' => 'unsupported_platform',
        default => 'path_inconclusive',
    };
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

        @if ($errorCount > 0)
            <h2>{{ __('site.check_store.audit_errors_title') }}</h2>
            <p>{{ __('site.check_store.audit_error_summary', ['count' => $errorCount]) }} {{ $checkoutReached ? __('site.check_store.audit_error_path_passed') : __('site.check_store.audit_error_path_unknown') }}</p>
            <div class="site-plans">
                @foreach ($errors->take(8) as $error)
                    @php
                        $causeCode = $error['cause_code'] ?? match ($error['type'] ?? '') {
                            'request_failed' => 'network_failure',
                            'http_4xx_resource' => 'resource_http_error',
                            'http_5xx' => 'server_response',
                            'js_error' => 'script_exception',
                            default => 'unclassified_script',
                        };
                    @endphp
                    <article class="site-plan">
                        <p class="site-muted site-small">{{ __('site.check_store.audit_error_types.'.($error['type'] ?? 'js_error')) }} · {{ __('site.check_store.audit_error_occurrences', ['count' => max(1, (int) ($error['count'] ?? 1))]) }}</p>
                        <h3>{{ __('site.check_store.audit_error_labels.'.$causeCode) }}@if (! empty($error['status'])) · HTTP {{ (int) $error['status'] }}@endif</h3>
                        @if (! empty($error['message_code']) && ! in_array($error['message_code'], ['console_error', 'console_warning'], true))
                            <p class="site-muted site-small">{{ $error['message_code'] }}</p>
                        @endif
                        <p>{{ __('site.check_store.audit_error_causes.'.$causeCode) }}</p>
                        @if (! empty($error['path']))
                            <p>{{ $error['origin'] ?? '' }}{{ $error['path'] }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
            @if ($errors->count() > 8 || ! empty($check->diagnostics['public_errors_truncated']))
                <p class="site-muted site-small">{{ __('site.check_store.audit_error_more') }}</p>
            @endif
        @endif

        <section class="site-note">
            @if ($upgradeFinding === 'unsupported_platform')
                <h2>{{ __('site.check_store.audit_unsupported_next_title') }}</h2>
                <p>{{ __('site.check_store.audit_unsupported_body', ['store' => $check->normalized_host]) }}</p>
                <div class="site-actions">
                    <a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}">{{ __('site.check_store.audit_unsupported_contact') }}</a>
                </div>
            @else
                <h2>{{ __('site.check_store.audit_upgrade_title') }}</h2>
                <p>{{ __('site.check_store.audit_upgrade_findings.'.$upgradeFinding, ['store' => $check->normalized_host]) }}</p>
                <p>{{ __('site.check_store.audit_upgrade_unknown') }}</p>
                @if ($errorCount > 0)
                    <p>{{ __('site.check_store.audit_upgrade_errors') }}</p>
                @endif
                <h3>{{ __('site.check_store.audit_upgrade_connected_title') }}</h3>
                <ul>
                    <li>{{ __('site.check_store.audit_upgrade_connected.browser') }}</li>
                    <li>{{ __('site.check_store.audit_upgrade_connected.orders') }}</li>
                    <li>{{ __('site.check_store.audit_upgrade_connected.money') }}</li>
                </ul>
                <p class="site-muted site-small">{{ __('site.check_store.audit_upgrade_provider_note') }}</p>
                <div class="site-actions">
                    <a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ __('site.check_store.audit_upgrade_signup') }}</a>
                    <a class="site-button site-button-secondary" href="/app/login">{{ __('site.check_store.audit_upgrade_signin') }}</a>
                </div>
            @endif
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
                    @if (! empty($step['error_code']) && !(($audit['checkout_result'] ?? null) === 'unsupported_platform' && $step['error_code'] === 'adapter_unsupported'))
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
