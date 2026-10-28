<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PluginFunnelEvent;
use App\Support\Network\PublicDestinationPolicy;
use App\Support\Plugin\PluginConnectRequest;
use App\Support\Plugin\PluginFunnel;
use App\Support\Site\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** Entry points of the WooCommerce plugin's links (ADR 0084): a counted sign-up link and the one-click connection. */
class PluginLinkController extends Controller
{
    public function __construct(
        private readonly PluginFunnel $funnel,
    ) {}

    public function go(Request $request): RedirectResponse
    {
        $locale = PluginConnectRequest::locale($request->query('lang'));
        $placement = $this->placement($request->query('placement'), 'plugin_description');
        $this->funnel->click($request, $placement, PluginFunnelEvent::PATH_ONBOARDING, $locale);

        return redirect(SiteContent::url($locale, 'signup').'?'.http_build_query([
            'utm_source' => 'wordpress_plugin',
            'utm_medium' => PluginFunnelEvent::PATH_ONBOARDING,
            'utm_content' => $placement,
        ]))->header('Cache-Control', 'no-store');
    }

    public function connect(Request $request, PublicDestinationPolicy $policy): RedirectResponse|Response
    {
        $connect = PluginConnectRequest::fromQuery($request, $policy);
        $locale = PluginConnectRequest::locale($request->query('lang'));

        if ($connect === null) {
            return app(SiteController::class)->notice($locale, 'plugin_connect_invalid', 422);
        }

        $journey = $this->funnel->click($request, $this->placement($request->query('placement'), 'plugin_page'), PluginFunnelEvent::PATH_CONNECT, $locale);
        $connect->withJourney($journey)->remember($request);

        if (Auth::guard('web')->check()) {
            return redirect('/app/connect')->header('Cache-Control', 'no-store');
        }

        if ($request->query('start') === 'signup') {
            return redirect(SiteContent::url($locale, 'signup').'?'.http_build_query([
                'utm_source' => 'wordpress_plugin',
                'utm_medium' => PluginFunnelEvent::PATH_CONNECT,
            ]))->header('Cache-Control', 'no-store');
        }

        return redirect('/app/login?'.http_build_query(['redirect' => '/connect']))->header('Cache-Control', 'no-store');
    }

    private function placement(mixed $value, string $default): string
    {
        return is_string($value) && in_array($value, PluginFunnelEvent::PLACEMENTS, true) ? $value : $default;
    }
}
