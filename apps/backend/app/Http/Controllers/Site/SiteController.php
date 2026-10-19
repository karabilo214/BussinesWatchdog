<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Site\ContactInbox;
use App\Support\Site\Policies;
use App\Support\Site\PricingTable;
use App\Support\Site\SiteContent;
use App\Support\Site\SiteDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public site rendered on the server (ADR 0039): every page is complete HTML without JavaScript.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    public function root(Request $request): RedirectResponse
    {
        $locale = 'en';

        foreach ($request->getLanguages() as $language) {
            $code = strtolower(substr($language, 0, 2));

            if (in_array($code, PublicPage::LOCALES, true)) {
                $locale = $code;
                break;
            }
        }

        return redirect(SiteContent::url($locale), 302)->header('Vary', 'Accept-Language');
    }

    public function home(string $locale, SiteDefaults $defaults): Response
    {
        $page = $this->content->system(PublicPage::KIND_HOME);
        $version = $this->content->published($page, $locale);
        $builtin = $page === null ? null : $defaults->content($page, $locale);

        return $this->render('site.home', $locale, $page, $version, [
            'title' => $version?->title ?? trans('site.home.title', [], $locale),
            'body' => $version?->body ?? $builtin['body'] ?? '',
        ]);
    }

    public function pricing(Request $request, string $locale, PricingTable $pricing): Response
    {
        $page = $this->content->system(PublicPage::KIND_PRICING);
        $version = $this->content->published($page, $locale);
        $period = $request->query('period');
        $currency = $request->query('currency');
        $stores = $request->query('stores');

        return $this->render('site.pricing', $locale, $page, $version, [
            'title' => $version?->title ?? trans('site.pricing.title', [], $locale),
            'pricing' => $pricing->build($locale, is_string($period) ? $period : null, is_string($currency) ? $currency : null, is_string($stores) && ctype_digit($stores) && strlen($stores) <= 3 ? (int) $stores : 0),
        ]);
    }

    public function contact(Request $request, string $locale): Response
    {
        $page = $this->content->system(PublicPage::KIND_CONTACT);
        $version = $this->content->published($page, $locale);
        $privacy = app(Policies::class)->current(PublicPage::POLICY_PRIVACY_NOTICE, $locale);

        return $this->render('site.contact', $locale, $page, $version, [
            'title' => $version?->title ?? trans('site.contact.title', [], $locale),
            'sent' => $request->session()->get('contact_sent') === true,
            'privacy' => $privacy === null ? null : SiteContent::url($privacy->locale, (string) app(Policies::class)->page(PublicPage::POLICY_PRIVACY_NOTICE)?->path),
        ], private: true);
    }

    public function submitContact(Request $request, string $locale, ContactInbox $inbox): RedirectResponse
    {
        $back = SiteContent::url($locale, 'contact');

        if (trim((string) $request->input('website')) !== '') {
            return redirect($back)->with('contact_sent', true);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'company' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [], [
            'name' => trans('site.contact.name', [], $locale),
            'email' => trans('site.contact.email', [], $locale),
            'company' => trans('site.contact.company', [], $locale),
            'message' => trans('site.contact.message', [], $locale),
        ]);

        $key = 'site-contact|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('watchdog.site.contact_per_hour'))) {
            return redirect($back)->withInput()->withErrors(['form' => trans('site.contact.too_many', [], $locale)]);
        }

        RateLimiter::hit($key, 3600);
        $inbox->receive($locale, $validated);

        return redirect($back)->with('contact_sent', true);
    }

    public function page(string $locale, string $slug, ?string $child = null): Response
    {
        $path = $child === null ? $slug : $slug.'/'.$child;
        $page = $this->content->page($path);

        if ($page === null || $page->kind !== PublicPage::KIND_PAGE) {
            return $this->missing($locale);
        }

        $version = $this->content->published($page, $locale);

        if ($version === null) {
            return $this->missing($locale);
        }

        return $this->render('site.page', $locale, $page, $version, ['title' => $version->title]);
    }

    public function previewHtml(PublicPage $page, string $locale, string $title, ?string $description, string $body): string
    {
        if ($page->kind === PublicPage::KIND_HOME && $body === '') {
            $body = app(SiteDefaults::class)->content($page, $locale)['body'] ?? '';
        }

        $data = $this->chrome($locale, $page, null);
        $data['preview'] = true;
        $data['canonical'] = null;
        $data['alternates'] = [];

        return view($page->kind === PublicPage::KIND_HOME ? 'site.home' : 'site.page', $data + [
            'title' => $title,
            'description' => $description,
            'body' => $body,
        ])->render();
    }

    /** Paddle's default payment link: Paddle.js opens the checkout of the `_ptxn` transaction in the address. */
    public function pay(string $locale): Response
    {
        return response()->view('site.pay', array_merge($this->chrome($locale, null, null), [
            'path' => 'pay',
            'title' => trans('site.pay.title', [], $locale),
            'description' => null,
            'paddle' => ['token' => PaddleConfig::clientToken(), 'environment' => PaddleConfig::environment()],
        ]))->header('Cache-Control', 'no-store');
    }

    public function missing(string $locale): Response
    {
        return response()->view('site.missing', $this->chrome($locale, null, null) + ['title' => trans('site.missing.title', [], $locale), 'description' => null], 404)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, string $locale, ?PublicPage $page, ?PublicPageVersion $version, array $data, bool $private = false): Response
    {
        app()->setLocale($locale);
        $seconds = (int) config('watchdog.site.cache_seconds');

        return response()->view($view, $this->chrome($locale, $page, $version) + $data + [
            'description' => $version?->description ?? trans('site.meta.description', [], $locale),
            'body' => $version?->body,
        ])->header('Cache-Control', $private ? 'no-store' : "public, max-age={$seconds}");
    }

    /**
     * @return array<string, mixed>
     */
    private function chrome(string $locale, ?PublicPage $page, ?PublicPageVersion $version): array
    {
        app()->setLocale($locale);
        $origin = rtrim((string) config('app.url'), '/');
        $path = $page?->path ?? '';
        $locales = $page === null ? PublicPage::LOCALES : $this->content->locales($page);

        return [
            'locale' => $locale,
            'path' => $path,
            'canonical' => $page === null ? null : $origin.SiteContent::url($locale, $path),
            'alternates' => $page === null ? [] : array_map(fn (string $code): array => ['locale' => $code, 'href' => $origin.SiteContent::url($code, $path)], $locales),
            'languages' => array_map(fn (string $code): array => ['locale' => $code, 'href' => SiteContent::url($code, in_array($code, $locales, true) ? $path : ''), 'current' => $code === $locale], PublicPage::LOCALES),
            'header' => $this->content->menu($locale, PublicPage::MENU_HEADER),
            'footer' => $this->content->menu($locale, PublicPage::MENU_FOOTER),
            'preview' => false,
        ];
    }
}
