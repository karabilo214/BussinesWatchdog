<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Site\ContactInbox;
use App\Support\Site\MathCaptcha;
use App\Support\Site\Policies;
use App\Support\Site\PricingTable;
use App\Support\Site\SiteContent;
use App\Support\Site\SiteDefaults;
use App\Support\Site\SiteRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The public site rendered on the server (ADR 0039): every page is complete HTML without JavaScript.
 */
class SiteController extends Controller
{
    private const CONTACT_CAPTCHA_SESSION_KEY = 'contact_math_captcha';

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
            'title' => $version?->title ?? trans('site.pricing.meta_title', [], $locale),
            'description' => $version?->description ?? trans('site.pricing.meta_description', [], $locale),
            'pricing' => $pricing->build($locale, is_string($period) ? $period : null, is_string($currency) ? $currency : null, is_string($stores) && ctype_digit($stores) && strlen($stores) <= 3 ? (int) $stores : 0),
        ]);
    }

    public function contact(Request $request, string $locale, ContactInbox $inbox, MathCaptcha $captcha): Response
    {
        $page = $this->content->system(PublicPage::KIND_CONTACT);
        $version = $this->content->published($page, $locale);
        $privacy = app(Policies::class)->current(PublicPage::POLICY_PRIVACY_NOTICE, $locale);
        $limits = $inbox->attachmentLimits();
        $captcha->begin($request, self::CONTACT_CAPTCHA_SESSION_KEY);

        return $this->render('site.contact', $locale, $page, $version, [
            'title' => $version?->title ?? trans('site.contact.title', [], $locale),
            'sent' => $request->session()->get('contact_sent') === true,
            'privacy' => $privacy === null ? null : SiteContent::url($privacy->locale, (string) app(Policies::class)->page(PublicPage::POLICY_PRIVACY_NOTICE)?->path),
            'captcha_text_url' => SiteContent::url($locale, 'contact/captcha/text'),
            'captcha_url' => SiteContent::url($locale, 'contact/captcha').'?v='.bin2hex(random_bytes(4)),
            'attachment' => [
                'accept' => implode(',', array_merge(array_map(fn (string $extension): string => '.'.$extension, $limits['extensions']), $limits['content_types'])),
                'formats' => implode(', ', array_map('strtoupper', array_unique(array_map(fn (string $type): string => $type === 'jpeg' ? 'jpg' : $type, $limits['types'])))),
                'max_mb' => rtrim(rtrim(number_format($limits['max_bytes'] / 1048576, 1, '.', ''), '0'), '.'),
                'enabled' => $limits['types'] !== [],
                'max_bytes' => $limits['max_bytes'],
            ],
        ], private: true);
    }

    public function contactCaptcha(Request $request, string $locale, MathCaptcha $captcha): Response
    {
        return $captcha->image($request, self::CONTACT_CAPTCHA_SESSION_KEY, reuse: true);
    }

    public function contactCaptchaText(Request $request, string $locale, MathCaptcha $captcha): Response
    {
        app()->setLocale($locale);

        return $captcha->text($request, self::CONTACT_CAPTCHA_SESSION_KEY);
    }

    public function submitContact(Request $request, string $locale, ContactInbox $inbox, MathCaptcha $captcha): RedirectResponse
    {
        $back = SiteContent::url($locale, 'contact');
        $fields = ['name', 'email', 'company', 'message'];

        if (trim((string) $request->input('website')) !== '') {
            return redirect($back)->with('contact_sent', true);
        }

        $limits = $inbox->attachmentLimits();

        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'company' => ['nullable', 'string', 'max:160'],
                'message' => ['required', 'string', 'min:10', 'max:5000'],
                'captcha_answer' => ['required', 'integer', 'min:0', 'max:99'],
                'attachment' => $limits['types'] === [] ? ['prohibited'] : ['nullable', 'file', 'max:'.(int) floor($limits['max_bytes'] / 1024)],
            ], [
                'attachment.max' => trans('site.contact.attachment_errors.media_too_large', ['mb' => round($limits['max_bytes'] / 1048576, 1)], $locale),
                'attachment.uploaded' => trans('site.contact.attachment_errors.media_too_large', ['mb' => round($limits['max_bytes'] / 1048576, 1)], $locale),
            ], [
                'name' => trans('site.contact.name', [], $locale),
                'email' => trans('site.contact.email', [], $locale),
                'company' => trans('site.contact.company', [], $locale),
                'message' => trans('site.contact.message', [], $locale),
                'captcha_answer' => trans('site.contact.captcha', [], $locale),
                'attachment' => trans('site.contact.attachment', [], $locale),
            ]);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }

        if (! $captcha->passes($request, self::CONTACT_CAPTCHA_SESSION_KEY, (string) $validated['captcha_answer'])) {
            return redirect($back)->withInput($request->only($fields))->withErrors(['captcha_answer' => trans('site.contact.captcha_wrong', [], $locale)]);
        }

        $key = 'site-contact|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('watchdog.site.contact_per_hour'))) {
            return redirect($back)->withInput($request->only($fields))->withErrors(['form' => trans('site.contact.too_many', [], $locale)]);
        }

        try {
            $inbox->receive($locale, $validated, $request->file('attachment'));
        } catch (SiteRejected $rejected) {
            $code = match (true) {
                in_array($rejected->reasonCode, ['media_too_large', 'media_type_unsupported', 'media_dimensions_too_large', 'media_too_large_for_server'], true) => $rejected->reasonCode,
                $rejected->status >= 500 => 'unavailable',
                default => 'media_unreadable',
            };

            return redirect($back)->withInput($request->only($fields))->withErrors(['attachment' => trans('site.contact.attachment_errors.'.$code, ['mb' => round($limits['max_bytes'] / 1048576, 1)], $locale)]);
        }

        RateLimiter::hit($key, 3600);

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

    public function notice(string $locale, string $key, int $status): Response
    {
        return response()->view('site.notice', $this->chrome($locale, null, null) + ['key' => $key, 'title' => trans("site.notice.{$key}.title", [], $locale), 'description' => null], $status)
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
            'footer' => $this->content->footerGroups($locale),
            'preview' => false,
        ];
    }
}
