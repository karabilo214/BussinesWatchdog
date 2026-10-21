<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Models\PublicStorefrontCheck;
use App\Models\PublicStorefrontCheckArtifact;
use App\Support\Site\MathCaptcha;
use App\Support\Site\PublicStorefrontCheckRejected;
use App\Support\Site\PublicStorefrontCheckService;
use App\Support\Site\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublicStorefrontCheckController extends Controller
{
    private const CAPTCHA_SESSION_KEY = 'public_storefront_math_captcha';

    public function __construct(private readonly PublicStorefrontCheckService $checks, private readonly MathCaptcha $captcha) {}

    public function form(Request $request, string $locale): Response
    {
        return response()->view('site.check-store', $this->chrome($locale) + [
            'title' => trans('site.check_store.title', [], $locale),
            'description' => trans('site.check_store.meta', [], $locale),
            'check' => null,
            'artifacts' => [],
            'result_token' => null,
            'captcha_url' => SiteContent::url($locale, 'check-store/captcha').'?v='.bin2hex(random_bytes(4)),
        ])->header('Cache-Control', 'no-store');
    }

    public function captcha(Request $request, string $locale): Response
    {
        return $this->captcha->image($request, self::CAPTCHA_SESSION_KEY);
    }

    public function submit(Request $request, string $locale): RedirectResponse
    {
        $back = SiteContent::url($locale, 'check-store');
        try {
            $validated = $request->validate([
                'url' => ['required', 'string', 'max:2048'],
                'captcha_answer' => ['required', 'integer', 'min:0', 'max:99'],
            ], [], [
                'url' => trans('site.check_store.url', [], $locale),
                'captcha_answer' => trans('site.check_store.captcha', [], $locale),
            ]);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }

        if (! $this->captcha->passes($request, self::CAPTCHA_SESSION_KEY, (string) $validated['captcha_answer'])) {
            return redirect($back)
                ->withInput(['url' => $validated['url']])
                ->withErrors(['captcha_answer' => trans('site.check_store.errors.captcha_required', [], $locale)]);
        }

        try {
            $created = $this->checks->submit($locale, $validated['url'], $request);
        } catch (PublicStorefrontCheckRejected $rejected) {
            return redirect($back)
                ->withInput(['url' => $validated['url']])
                ->withErrors(['url' => trans('site.check_store.errors.'.$rejected->reasonCode, [], $locale)]);
        }

        return redirect(SiteContent::url($locale, 'check-store/'.$created['token']));
    }

    public function result(string $locale, string $token): Response
    {
        $check = $this->checks->byToken($token);

        if ($check === null) {
            return response()->view('site.missing', $this->chrome($locale) + [
                'title' => trans('site.missing.title', [], $locale),
                'description' => null,
            ], 404)->header('Cache-Control', 'no-store');
        }

        return response()->view('site.check-store', $this->chrome($locale) + [
            'title' => trans('site.check_store.result_title', [], $locale),
            'description' => trans('site.check_store.meta', [], $locale),
            'check' => $check,
            'artifacts' => $check->artifacts,
            'result_token' => $token,
        ])->header('Cache-Control', 'no-store');
    }

    public function status(string $locale, string $token): JsonResponse
    {
        app()->setLocale($locale);

        $check = $this->checks->byToken($token);

        if ($check === null) {
            return response()->json(['error' => 'not_found'], 404, ['Cache-Control' => 'no-store']);
        }

        $terminal = ! in_array($check->status, [PublicStorefrontCheck::STATUS_QUEUED, PublicStorefrontCheck::STATUS_RUNNING], true);

        return response()->json([
            'status' => $check->status,
            'terminal' => $terminal,
            'poll_after_ms' => $terminal ? null : 2500,
            'html' => view('site.partials.check-store-result', [
                'locale' => $locale,
                'check' => $check,
                'artifacts' => $check->artifacts,
                'result_token' => $token,
            ])->render(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function artifact(string $locale, string $token, string $artifactId): Response
    {
        $check = $this->checks->byToken($token);

        if ($check === null || $check->expires_at->isPast()) {
            abort(404);
        }

        /** @var PublicStorefrontCheckArtifact|null $artifact */
        $artifact = PublicStorefrontCheckArtifact::query()
            ->where('check_id', $check->id)
            ->whereKey($artifactId)
            ->where('state', PublicStorefrontCheckArtifact::STATE_READY)
            ->first();

        if ($artifact === null || $artifact->expires_at->isPast()) {
            abort(404);
        }

        $body = Storage::disk((string) config('watchdog.artifacts.disk'))->get($artifact->object_key);

        return response($body, 200, [
            'Content-Type' => $artifact->content_type,
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chrome(string $locale): array
    {
        app()->setLocale($locale);

        return [
            'locale' => $locale,
            'path' => 'check-store',
            'canonical' => null,
            'alternates' => [],
            'languages' => array_map(fn (string $code): array => ['locale' => $code, 'href' => SiteContent::url($code, 'check-store'), 'current' => $code === $locale], PublicPage::LOCALES),
            'header' => app(SiteContent::class)->menu($locale, PublicPage::MENU_HEADER),
            'footer' => app(SiteContent::class)->menu($locale, PublicPage::MENU_FOOTER),
            'preview' => false,
        ];
    }
}
