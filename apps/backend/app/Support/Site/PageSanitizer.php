<?php

namespace App\Support\Site;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Keeps the markup of a public page and removes everything that runs code or changes the page around it: scripts,
 * frames, forms, event handlers, inline styles and links other than http(s), mailto and tel (ADR 0039).
 */
class PageSanitizer
{
    public const MAX_LENGTH = 200000;

    private const DROPPED = ['form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'fieldset', 'legend', 'datalist', 'output', 'meter', 'progress', 'dialog', 'template', 'slot'];

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer()->sanitize($html));
    }

    private function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowAttribute('id', '*')
            ->dropAttribute('style', '*')
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https'])
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(self::MAX_LENGTH);

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
