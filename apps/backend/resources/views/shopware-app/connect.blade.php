@php
    $t = [
        'de' => [
            'title' => 'Business Watchdog verbinden',
            'intro' => 'Öffnen Sie in Ihrem Business-Watchdog-Konto den Shop, wählen Sie „Zusätzliche Checkout-Beobachtung mit Shopware App“ und klicken Sie auf „Verbindungscode erstellen“. Fügen Sie ihn hier ein. Der Code gilt 15 Minuten; die Adresse dieses Shops muss mit der Shop-Adresse in Business Watchdog übereinstimmen.',
            'code' => 'Verbindungscode',
            'submit' => 'Verbinden',
            'connected' => 'Verbunden mit dem Shop „:store“. Business Watchdog liest jetzt Bestellungen und Zahlungsstatus (nur lesend).',
            'bound' => 'Diese Installation ist mit dem Shop „:store“ verbunden.',
            'invalid' => 'Diese Seite wurde nicht von Ihrer Shopware-Administration geöffnet oder ist abgelaufen. Öffnen Sie die App erneut unter „Erweiterungen“.',
            'errors' => [
                'pairing_not_found' => 'Der Code wurde nicht gefunden. Kopieren Sie ihn erneut aus Business Watchdog.',
                'pairing_consumed' => 'Der Code wurde bereits verwendet. Erstellen Sie einen neuen.',
                'pairing_expired' => 'Der Code ist abgelaufen. Erstellen Sie einen neuen.',
                'pairing_attempts_exceeded' => 'Zu viele Versuche mit diesem Code. Erstellen Sie einen neuen.',
                'pairing_platform_mismatch' => 'Der Code gehört zu einem Shop, der in Business Watchdog nicht als Shopware-Shop angelegt ist.',
                'pairing_base_url_mismatch' => 'Die Adresse dieses Shops stimmt nicht mit der Shop-Adresse in Business Watchdog überein.',
                'shopware_app_not_confirmed' => 'Die App ist noch nicht vollständig registriert. Aktivieren Sie sie und versuchen Sie es in einer Minute erneut.',
                'shopware_app_shop_not_reachable' => 'Business Watchdog konnte sich mit dem Schlüssel der App nicht bei diesem Shop anmelden. Ist die App aktiviert?',
                'provider_already_connected' => 'Der Shop ist bereits mit Shopware verbunden.',
                'order_source_already_connected' => 'Der Shop ist bereits über eine andere Verbindung angebunden.',
                'shopware_app_already_bound' => 'Diese Installation ist bereits verbunden.',
            ],
            'error' => 'Die Verbindung ist fehlgeschlagen.',
        ],
        'en' => [
            'title' => 'Connect Business Watchdog',
            'intro' => 'In your Business Watchdog account open the store, choose «Additional checkout observation with Shopware App» and click «Create connection code». Paste it here. The code is valid for 15 minutes; the address of this shop must match the store address in Business Watchdog.',
            'code' => 'Connection code',
            'submit' => 'Connect',
            'connected' => 'Connected to the store «:store». Business Watchdog now reads orders and payment states (read-only).',
            'bound' => 'This installation is connected to the store «:store».',
            'invalid' => 'This page was not opened from your Shopware Administration or has expired. Open the app again under «Extensions».',
            'errors' => [
                'pairing_not_found' => 'The code was not found. Copy it again from Business Watchdog.',
                'pairing_consumed' => 'The code was already used. Create a new one.',
                'pairing_expired' => 'The code has expired. Create a new one.',
                'pairing_attempts_exceeded' => 'Too many attempts with this code. Create a new one.',
                'pairing_platform_mismatch' => 'The code belongs to a store that is not set up as a Shopware store in Business Watchdog.',
                'pairing_base_url_mismatch' => 'The address of this shop does not match the store address in Business Watchdog.',
                'shopware_app_not_confirmed' => 'The app has not finished registering yet. Activate it and try again in a minute.',
                'shopware_app_shop_not_reachable' => 'Business Watchdog could not sign in to this shop with the app\'s key. Is the app active?',
                'provider_already_connected' => 'The store is already connected to Shopware.',
                'order_source_already_connected' => 'The store is already connected through another connection.',
                'shopware_app_already_bound' => 'This installation is already connected.',
            ],
            'error' => 'The connection failed.',
        ],
    ][$locale];
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $t['title'] }}</title>
    <style>
        body { margin: 0; padding: 24px; font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1f2937; background: #fff; }
        main { max-width: 560px; }
        h1 { font-size: 20px; margin: 0 0 12px; color: #24549f; }
        p { margin: 0 0 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input { box-sizing: border-box; width: 100%; padding: 8px 10px; font: 14px ui-monospace, monospace; border: 1px solid #9ca3af; border-radius: 6px; }
        button { margin-top: 12px; padding: 8px 16px; font-weight: 600; color: #fff; background: #24549f; border: 0; border-radius: 6px; cursor: pointer; }
        .note { padding: 10px 12px; border-radius: 6px; }
        .ok { background: #ecfdf3; color: #146c43; border: 1px solid #a7e3c4; }
        .err { background: #fdf2f2; color: #9b1c1c; border: 1px solid #f3b4b4; }
    </style>
</head>
<body>
<main>
    <h1>{{ $t['title'] }}</h1>
    @if ($signedQuery === null)
        <p class="note err" role="alert">{{ $t['invalid'] }}</p>
    @elseif ($state === 'connected' || $bound)
        <p class="note ok" role="status">{{ str_replace(':store', (string) $storeName, $state === 'connected' ? $t['connected'] : $t['bound']) }}</p>
    @else
        <p>{{ $t['intro'] }}</p>
        @if ($error)
            <p class="note err" role="alert">{{ $t['errors'][$error] ?? $t['error'] }}</p>
        @endif
        <form method="post" action="{{ url('/api/v1/shopware/app/connect') }}">
            <input type="hidden" name="signed_query" value="{{ $signedQuery }}">
            <label for="code">{{ $t['code'] }}</label>
            <input id="code" name="code" autocomplete="off" spellcheck="false" required maxlength="100" placeholder="bwpc_…">
            <button type="submit">{{ $t['submit'] }}</button>
        </form>
    @endif
</main>
<script>try { window.parent.postMessage('sw-app-loaded', '*'); } catch (e) {}</script>
</body>
</html>
