<?php

namespace App\Support\Mail;

final class MailBody
{
    private const LINE_LINK = '/^(?:(?<label>[^:\n]{1,60}):\s+)?(?<url>https?:\/\/\S+)$/u';

    private const INLINE_URL = '/https?:\/\/[^\s<>"«»]+/u';

    /**
     * @param  list<string>  $trustedHosts
     * @return list<array{type: 'paragraph', lines: list<list<array{text: string, url?: string}>>}|array{type: 'button', label: string, url: string}>
     */
    public static function blocks(string $text, string $actionLabel, string $linkLabel, array $trustedHosts): array
    {
        $blocks = [];
        $lines = [];
        $flush = function () use (&$blocks, &$lines): void {
            if ($lines !== []) {
                $blocks[] = ['type' => 'paragraph', 'lines' => $lines];
                $lines = [];
            }
        };

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flush();

                continue;
            }

            if (preg_match(self::LINE_LINK, $trimmed, $match) === 1 && self::trusted($match['url'], $trustedHosts)) {
                $label = trim($match['label'] ?? '');

                if (preg_match('/^\s/u', $line) === 1) {
                    $lines[] = [['text' => $label !== '' ? $label : $linkLabel, 'url' => $match['url']]];

                    continue;
                }

                $flush();
                $blocks[] = ['type' => 'button', 'label' => $label !== '' ? $label : $actionLabel, 'url' => $match['url']];

                continue;
            }

            $lines[] = self::segments($trimmed, $trustedHosts);
        }

        $flush();

        return $blocks;
    }

    /**
     * @param  list<string>  $trustedHosts
     */
    public static function trusted(string $url, array $trustedHosts): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(mb_strtolower($parts['host'] ?? ''), $trustedHosts, true);
    }

    /**
     * @param  list<string>  $trustedHosts
     * @return list<array{text: string, url?: string}>
     */
    private static function segments(string $line, array $trustedHosts): array
    {
        $segments = [];
        $offset = 0;

        preg_match_all(self::INLINE_URL, $line, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$url, $position]) {
            $url = rtrim($url, '.,;:!?)');

            if (! self::trusted($url, $trustedHosts)) {
                continue;
            }

            if ($position > $offset) {
                $segments[] = ['text' => substr($line, $offset, $position - $offset)];
            }

            $segments[] = ['text' => $url, 'url' => $url];
            $offset = $position + strlen($url);
        }

        if ($offset < strlen($line)) {
            $segments[] = ['text' => substr($line, $offset)];
        }

        return $segments;
    }
}
