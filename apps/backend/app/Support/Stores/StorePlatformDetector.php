<?php

namespace App\Support\Stores;

use App\Support\Network\StoreOriginFailure;
use App\Support\Network\StoreOriginHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Advisory detection from public HTML only. An unknown result never overrides the customer's choice. */
final class StorePlatformDetector
{
    public function __construct(private readonly StoreOriginHttp $http) {}

    public function detect(string $baseUrl): ?string
    {
        try {
            $response = $this->http->send($baseUrl, '', StoreOriginHttp::base(8)->accept('text/html'),
                fn (PendingRequest $request, string $url): Response => $request->get($url));
        } catch (StoreOriginFailure) {
            return null;
        }

        if (! $response->successful() || strlen($response->body()) > 262144) {
            return null;
        }

        return self::fromHtml($response->body());
    }

    public static function fromHtml(string $html): ?string
    {
        $signals = [
            'woocommerce' => ['/woocommerce/i', '/wc-ajax/i', '/wc-block/i', '#wp-content/plugins/woocommerce#i', '#wp-json/wc/store#i'],
            'shopware' => ['/shopware/i', '#/checkout/line-item/add#i', '/storefront\.js/i', '/\bis-ctl-/i', '#/store-api/#i', '/sales_channel_context/i'],
        ];
        $score = [];

        foreach ($signals as $platform => $patterns) {
            $score[$platform] = count(array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $html) === 1));
        }

        return $score['woocommerce'] > $score['shopware'] && $score['woocommerce'] > 0 ? 'woocommerce'
            : ($score['shopware'] > $score['woocommerce'] && $score['shopware'] > 0 ? 'shopware' : null);
    }
}
