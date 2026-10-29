@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <h1>{{ $title }}</h1>
        <div class="site-card site-pay" data-pay>
            <p data-pay-state="opening">{{ __('site.pay.opening') }}</p>
            <p data-pay-state="none" hidden>{{ __('site.pay.none') }}</p>
            <p data-pay-state="failed" hidden>{{ __('site.pay.failed') }}</p>
            <p class="site-muted site-small">{{ __('site.pay.reseller') }}</p>
            <p class="site-actions"><a class="site-button site-button-secondary" href="/app/settings/billing">{{ __('site.pay.account') }}</a></p>
        </div>
    </section>
@endsection

@push('scripts')
    <script type="module" nonce="{{ request()->attributes->get('csp_nonce') }}">
        @if ($paddleScript = \App\Support\Site\SiteAssets::paddleScript())
            import @json($paddleScript);
        @endif
        (function () {
            var token = @json($paddle['token']);
            var sandbox = @json($paddle['environment'] === 'sandbox');
            var box = document.querySelector('[data-pay]');
            function show(state) {
                box.querySelectorAll('[data-pay-state]').forEach(function (node) { node.hidden = node.getAttribute('data-pay-state') !== state; });
            }
            if (!/[?&]_ptxn=txn_[0-9a-z]+/.test(window.location.search) || !token) {
                show('none');
                return;
            }
            var script = document.createElement('script');
            script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
            script.async = true;
            script.onerror = function () { show('failed'); };
            script.onload = function () {
                if (!window.Paddle) { show('failed'); return; }
                if (sandbox) { window.Paddle.Environment.set('sandbox'); }
                window.Paddle.Initialize({ token: token, checkout: { settings: { successUrl: window.location.origin + '/app/settings/billing' } } });
            };
            document.head.appendChild(script);
        })();
    </script>
@endpush
