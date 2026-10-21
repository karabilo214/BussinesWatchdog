<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Support\Account\EmailVerificationService;
use App\Support\Account\Registration;
use App\Support\Site\Policies;
use App\Support\Site\PolicyAcceptanceRequired;
use App\Support\Site\PricingTable;
use App\Support\Site\SiteContent;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Sign-up on the public site, rendered on the server (ADR 0042): account, organisation and consent to the current
 * service terms and privacy notice in one step, then the customer account opens on the chosen plan.
 */
class SignupController extends Controller
{
    private const DEFAULT_TIMEZONE = ['ru' => 'Europe/Moscow', 'en' => 'UTC', 'de' => 'Europe/Berlin'];

    public function __construct(
        private readonly SiteContent $content,
        private readonly Policies $policies,
    ) {}

    public function show(Request $request, string $locale): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect($this->target($request->query('plan'), $request->query('price'), $request->query('stores')));
        }

        app()->setLocale($locale);
        $origin = rtrim((string) config('app.url'), '/');

        return response()->view('site.signup', [
            'locale' => $locale,
            'path' => 'signup',
            'title' => trans('site.signup.title', [], $locale),
            'description' => trans('site.signup.lead', [], $locale),
            'canonical' => $origin.SiteContent::url($locale, 'signup'),
            'alternates' => array_map(fn (string $code): array => ['locale' => $code, 'href' => $origin.SiteContent::url($code, 'signup')], PublicPage::LOCALES),
            'languages' => array_map(fn (string $code): array => ['locale' => $code, 'href' => SiteContent::url($code, 'signup'), 'current' => $code === $locale], PublicPage::LOCALES),
            'header' => $this->content->menu($locale, PublicPage::MENU_HEADER),
            'footer' => $this->content->menu($locale, PublicPage::MENU_FOOTER),
            'preview' => false,
            'documents' => $this->policies->required(Policies::PURPOSE_SIGNUP, $locale),
            'plan' => old('plan', $request->query('plan')),
            'price' => old('price', $request->query('price')),
            'stores' => old('stores', $request->query('stores')),
            'login_target' => $this->target(old('plan', $request->query('plan')), old('price', $request->query('price')), old('stores', $request->query('stores'))),
            'timezones' => DateTimeZone::listIdentifiers(),
            'timezone' => old('timezone', self::DEFAULT_TIMEZONE[$locale]),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $locale, Registration $registration, EmailVerificationService $verification): RedirectResponse
    {
        $back = SiteContent::url($locale, 'signup');

        if (trim((string) $request->input('website')) !== '') {
            return redirect($back);
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $documents = $this->policies->required(Policies::PURPOSE_SIGNUP, $locale);
        $labels = fn (string $field): string => trans("site.signup.{$field}", [], $locale);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'organization_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
            'timezone' => ['required', 'timezone'],
            'plan' => ['nullable', 'string', 'max:40'],
            'price' => ['nullable', 'uuid'],
            'stores' => ['nullable', 'integer', 'min:0', 'max:'.PricingTable::EXTRA_STORES_CEILING],
            'accept' => $documents === [] ? ['nullable'] : ['accepted'],
            'policy_version_ids' => ['sometimes', 'array', 'max:10'],
            'policy_version_ids.*' => ['string', 'uuid'],
        ], [
            'email.unique' => trans('site.signup.email_taken', ['login' => '/app/login'], $locale),
            'accept.accepted' => trans('site.signup.accept_required', [], $locale),
        ], [
            'name' => $labels('name'),
            'organization_name' => $labels('organization'),
            'email' => $labels('email'),
            'password' => $labels('password'),
            'timezone' => $labels('timezone'),
        ]);

        $key = 'auth-signup|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('watchdog.rate_limits.signup_per_hour'))) {
            return redirect($back)->withInput($request->except(['password', 'password_confirmation', 'accept']))->withErrors(['form' => trans('site.signup.too_many', [], $locale)]);
        }

        RateLimiter::hit($key, 3600);

        try {
            [$user, $tenant] = $registration->register($validated + ['locale' => $locale], array_values((array) ($validated['policy_version_ids'] ?? [])));
        } catch (PolicyAcceptanceRequired) {
            return redirect($back)->withInput($request->except(['password', 'password_confirmation', 'accept']))->withErrors(['accept' => trans('site.signup.policies_changed', [], $locale)]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('active_tenant_id', $tenant->id);

        try {
            $verification->send($user);
        } catch (Throwable $exception) {
            Log::warning('site.signup_verification_failed', ['user_id' => $user->id, 'exception' => $exception::class]);
        }

        return redirect($this->target($validated['plan'] ?? null, $validated['price'] ?? null, $validated['stores'] ?? null));
    }

    private function target(mixed $plan, mixed $price = null, mixed $stores = null): string
    {
        if (! is_string($plan) || preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $plan) !== 1) {
            return '/app/settings/billing';
        }

        return '/app/settings/billing?'.http_build_query(array_filter([
            'plan' => $plan,
            'price' => is_string($price) && Str::isUuid($price) ? $price : null,
            'stores' => is_scalar($stores) && preg_match('/^\d{1,3}$/', (string) $stores) === 1 ? min((int) $stores, PricingTable::EXTRA_STORES_CEILING) : null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
