<?php

namespace Tests\Unit;

use App\Support\Network\StoreProtectionPage;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class StoreProtectionPageTest extends TestCase
{
    public function test_distinguishes_protection_pages_from_api_errors(): void
    {
        $response = fn (int $status, array $headers, string $body) => new Response(new PsrResponse($status, $headers, $body));

        $this->assertSame('basic_auth', StoreProtectionPage::reason($response(401, ['WWW-Authenticate' => 'Basic realm="shop"'], '')));
        $this->assertSame('challenge', StoreProtectionPage::reason($response(403, ['Content-Type' => 'text/html'], '<html><title>Just a moment...</title><div class="cf-chl-bypass">')));
        $this->assertSame('maintenance', StoreProtectionPage::reason($response(503, ['Content-Type' => 'text/html'], '<html>Wartungsarbeiten</html>')));
        $this->assertNull(StoreProtectionPage::reason($response(403, ['Content-Type' => 'application/json'], '{"code":"woocommerce_rest_cannot_view"}')));
    }
}
