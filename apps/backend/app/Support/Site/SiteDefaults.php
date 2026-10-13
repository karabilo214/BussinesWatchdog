<?php

namespace App\Support\Site;

use App\Models\PublicPage;

class SiteDefaults
{
    public function __construct(private readonly PageSanitizer $sanitizer) {}

    /**
     * @return array{title: string, description: string, body: string}|null
     */
    public function content(PublicPage $page, string $locale): ?array
    {
        if ($page->kind !== PublicPage::KIND_HOME) {
            return null;
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return [
                'title' => trans('site.home.title'),
                'description' => trans('site.meta.description'),
                'body' => $this->sanitizer->sanitize(view('site.builtin.home', ['locale' => $locale])->render()),
            ];
        } finally {
            app()->setLocale($previous);
        }
    }
}
