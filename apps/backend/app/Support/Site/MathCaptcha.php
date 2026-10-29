<?php

namespace App\Support\Site;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The self-hosted image CAPTCHA of the public forms (a sum of two digits): no third-party script and no cookie beyond
 * the session. Each form keeps its challenge under its own session key; an answer is checked once and the challenge is
 * dropped whatever the result. Contact forms reuse the same challenge for image and accessible text.
 */
class MathCaptcha
{
    public function image(Request $request, string $sessionKey, bool $reuse = false): Response
    {
        $question = $reuse ? $this->question($request, $sessionKey) : $this->issue($request, $sessionKey);
        $image = function_exists('imagecreatetruecolor') ? $this->png($question) : $this->svg($question);

        return response($image['body'], 200, [
            'Content-Type' => $image['content_type'],
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function begin(Request $request, string $sessionKey): void
    {
        $this->issue($request, $sessionKey);
    }

    public function text(Request $request, string $sessionKey): Response
    {
        [$left, $right] = explode(' + ', $this->question($request, $sessionKey));

        return response(trans('site.forms.captcha_question', compact('left', 'right')), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function question(Request $request, string $sessionKey): string
    {
        $challenge = $request->session()->get($sessionKey);
        if (is_array($challenge) && is_string($challenge['question'] ?? null)
            && ($challenge['expires_at'] ?? 0) >= Carbon::now()->timestamp) {
            return $challenge['question'];
        }

        return $this->issue($request, $sessionKey);
    }

    public function passes(Request $request, string $sessionKey, string $answer): bool
    {
        $challenge = $request->session()->pull($sessionKey);

        return is_array($challenge)
            && is_string($challenge['hash'] ?? null)
            && is_int($challenge['expires_at'] ?? null)
            && $challenge['expires_at'] >= Carbon::now()->timestamp
            && hash_equals($challenge['hash'], hash_hmac('sha256', trim($answer), (string) config('app.key')));
    }

    private function issue(Request $request, string $sessionKey): string
    {
        $left = random_int(2, 9);
        $right = random_int(2, 9);
        $question = $left.' + '.$right;

        $request->session()->put($sessionKey, [
            'question' => $question,
            'hash' => hash_hmac('sha256', (string) ($left + $right), (string) config('app.key')),
            'expires_at' => Carbon::now()->addMinutes((int) config('watchdog.public_check.captcha_ttl_minutes'))->timestamp,
        ]);

        return $question;
    }

    /**
     * @return array{body: string, content_type: string}
     */
    private function png(string $question): array
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
    private function svg(string $question): array
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
