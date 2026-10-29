<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_nonces_are_request_local_and_match_only_explicit_template_attributes(): void
    {
        Route::get('/security-test', fn () => response('<script nonce="'.request()->attributes->get('csp_nonce').'">test</script>'));
        $first = $this->get('/security-test')->assertOk();
        $second = $this->get('/security-test')->assertOk();
        $policy = $first->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy);
        preg_match("/'nonce-([^']+)'/", $policy, $matches);
        $first->assertSee('nonce="'.$matches[1].'"', false);
        $this->assertNotSame($policy, $second->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("'strict-dynamic'", $policy);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $first->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
    }

    public function test_redirects_and_errors_have_security_headers(): void
    {
        Route::get('/security-redirect', fn () => redirect('/en'));
        Route::get('/security-error', fn () => abort(403));
        foreach (['/security-redirect' => 302, '/security-error' => 403, '/security-missing' => 404] as $url => $status) {
            $this->get($url)->assertStatus($status)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Content-Security-Policy');
        }
    }

    public function test_existing_private_artifact_sandbox_is_not_replaced(): void
    {
        Route::get('/security-artifact', fn () => response('file', 200, ['Content-Security-Policy' => "default-src 'none'; sandbox"]));
        $this->get('/security-artifact')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function test_report_only_gate_and_checkout_trusted_types_enforcement_are_explicit(): void
    {
        Route::get('/security-test', fn () => response('ok'));
        config()->set('security.csp_report_only', true);
        $this->get('/security-test')->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');
        config()->set('security.csp_report_only', false);
        // Isolate header behavior from the site's database, token and billing configuration.
        Route::get('/en/pay', fn () => response('checkout'));
        $pay = $this->get('/en/pay')->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("require-trusted-types-for 'script'; trusted-types default", $pay->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('https://*.paddle.com', $pay->headers->get('Content-Security-Policy'));
    }
}
