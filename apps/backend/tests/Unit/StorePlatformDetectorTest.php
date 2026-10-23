<?php

namespace Tests\Unit;

use App\Support\Stores\StorePlatformDetector;
use PHPUnit\Framework\TestCase;

class StorePlatformDetectorTest extends TestCase
{
    public function test_public_html_hints_are_advisory(): void
    {
        $this->assertSame('woocommerce', StorePlatformDetector::fromHtml('<html><link href="/wp-content/plugins/woocommerce/assets/x.css"><body class="woocommerce">'));
        $this->assertSame('shopware', StorePlatformDetector::fromHtml('<html><meta name="generator" content="Shopware"><body class="is-ctl-product">'));
        $this->assertNull(StorePlatformDetector::fromHtml('<html><body>Online shop</body></html>'));
        $this->assertNull(StorePlatformDetector::fromHtml('<html>woocommerce shopware</html>'));
    }
}
