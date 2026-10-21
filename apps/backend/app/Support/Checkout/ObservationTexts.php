<?php

namespace App\Support\Checkout;

/**
 * Sample texts customers copy for their stores about checkout observation (ADR 0062, lawyer's answer): the paragraph
 * for the store's privacy policy and a legitimate interest assessment, in German and English, with the provider
 * from the configuration.
 */
class ObservationTexts
{
    public const LOCALES = ['de', 'en'];

    public const KINDS = ['privacy', 'lia'];

    /**
     * @return array{provider_set: bool, texts: array<string, array<string, string>>}
     */
    public function all(): array
    {
        $name = trim((string) config('watchdog.provider.name'));
        $address = trim((string) config('watchdog.provider.address'));
        $texts = [];

        foreach (self::LOCALES as $locale) {
            $provider = $name === '' ? ($locale === 'de' ? '[Name und Anschrift des Anbieters]' : '[provider name and address]') : ($address === '' ? $name : $name.', '.$address);

            foreach (self::KINDS as $kind) {
                $texts[$locale][$kind] = str_replace(':provider', $provider, (string) file_get_contents(resource_path("merchant-texts/{$locale}-{$kind}.txt")));
            }
        }

        return ['provider_set' => $name !== '', 'texts' => $texts];
    }
}
