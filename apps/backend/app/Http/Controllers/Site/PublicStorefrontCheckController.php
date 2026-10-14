<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicPage;
use App\Models\PublicStorefrontCheck;
use App\Models\PublicStorefrontCheckArtifact;
use App\Support\Site\PublicStorefrontCheckRejected;
use App\Support\Site\PublicStorefrontCheckService;
use App\Support\Site\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PublicStorefrontCheckController extends Controller
{
    private const CAPTCHA_SESSION_KEY = 'public_storefront_math_captcha';

    public function __construct(private readonly PublicStorefrontCheckService $checks) {}

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
        $challenge = $this->newCaptcha($request);
        $image = function_exists('imagecreatetruecolor') ? $this->captchaPng($challenge['question']) : $this->captchaSvg($challenge['question']);

        return response($image['body'], 200, [
            'Content-Type' => $image['content_type'],
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function submit(Request $request, string $locale): RedirectResponse
    {
        $back = SiteContent::url($locale, 'check-store');
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'captcha_answer' => ['required', 'integer', 'min:0', 'max:99'],
        ], [], [
            'url' => trans('site.check_store.url', [], $locale),
            'captcha_answer' => trans('site.check_store.captcha', [], $locale),
        ]);

        if (! $this->captchaAnswerValid($request, (string) $validated['captcha_answer'])) {
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

    /**
     * @return array{question: string, answer: int}
     */
    private function newCaptcha(Request $request): array
    {
        $left = random_int(2, 9);
        $right = random_int(2, 9);
        $answer = $left + $right;
        $question = $left.' + '.$right;

        $request->session()->put(self::CAPTCHA_SESSION_KEY, [
            'question' => $question,
            'hash' => hash_hmac('sha256', (string) $answer, (string) config('app.key')),
            'expires_at' => Carbon::now()->addMinutes((int) config('watchdog.public_check.captcha_ttl_minutes'))->timestamp,
        ]);

        return ['question' => $question, 'answer' => $answer];
    }

    private function captchaAnswerValid(Request $request, string $answer): bool
    {
        $challenge = $request->session()->pull(self::CAPTCHA_SESSION_KEY);

        return is_array($challenge)
            && is_string($challenge['hash'] ?? null)
            && is_int($challenge['expires_at'] ?? null)
            && $challenge['expires_at'] >= Carbon::now()->timestamp
            && hash_equals($challenge['hash'], hash_hmac('sha256', trim($answer), (string) config('app.key')));
    }

    /**
     * @return array{body: string, content_type: string}
     */
    private function captchaPng(string $question): array
    {
        $width = 156;
        $height = 54;
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 246, 248, 252);
        $ink = imagecolorallocate($image, 21, 55, 91);
        $muted = imagecolorallocate($image, 102, 121, 145);
        $line = imagecolorallocate($image, 181, 194, 212);

        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        for ($i = 0; $i < 12; $i++) {
            imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $line);
        }

        for ($i = 0; $i < 160; $i++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $muted);
        }

        $chars = str_split($question);
        $x = 28;

        foreach ($chars as $char) {
            $y = random_int(15, 25);
            $color = random_int(0, 1) === 1 ? $ink : $muted;
            imagestring($image, 5, $x + random_int(-2, 2), $y, $char, $color);
            $x += $char === ' ' ? 9 : random_int(15, 18);
        }

        imagerectangle($image, 0, 0, $width - 1, $height - 1, $line);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return ['body' => $png, 'content_type' => 'image/png'];
    }

    /**
     * @return array{body: string, content_type: string}
     */
    private function captchaSvg(string $question): array
    {
        $lines = [];
        $dots = [];

        for ($i = 0; $i < 14; $i++) {
            $lines[] = '<line x1="'.random_int(0, 156).'" y1="'.random_int(0, 54).'" x2="'.random_int(0, 156).'" y2="'.random_int(0, 54).'" stroke="#b5c2d4" stroke-width="1" opacity="0.75" />';
        }

        for ($i = 0; $i < 90; $i++) {
            $dots[] = '<circle cx="'.random_int(0, 156).'" cy="'.random_int(0, 54).'" r="'.random_int(1, 2).'" fill="#667991" opacity="0.55" />';
        }

        $x = 28;
        $chars = [];

        foreach (str_split($question) as $char) {
            $chars[] = '<text x="'.$x.'" y="'.random_int(30, 38).'" font-family="monospace" font-size="24" font-weight="700" fill="'.(random_int(0, 1) === 1 ? '#15375b' : '#667991').'" transform="rotate('.random_int(-10, 10).' '.$x.' 30)">'.e($char).'</text>';
            $x += $char === ' ' ? 11 : random_int(17, 20);
        }

        return [
            'body' => '<svg xmlns="http://www.w3.org/2000/svg" width="156" height="54" viewBox="0 0 156 54" role="img"><rect width="156" height="54" fill="#f6f8fc"/>'.implode('', $lines).implode('', $dots).implode('', $chars).'<rect x="0.5" y="0.5" width="155" height="53" fill="none" stroke="#b5c2d4"/></svg>',
            'content_type' => 'image/svg+xml',
        ];
    }
}
