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
