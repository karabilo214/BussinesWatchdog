<?php

namespace App\Support\Site;

use App\Models\PublicMenuSection;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use Illuminate\Support\Facades\DB;

/** Initial content only: later releases never replace editorial changes or restore removed publications. */
class SeoPageImporter
{
    public function __construct(
        private readonly PageAdmin $pages,
        private readonly MenuSectionAdmin $sections,
        private readonly PageSanitizer $sanitizer,
    ) {}

    public function import(string $staffId, ?string $reason): array
    {
        $catalog = require database_path('data/seo/catalog.php');

        return DB::transaction(function () use ($staffId, $reason, $catalog): array {
            $counts = ['pages_created' => 0, 'drafts_created' => 0, 'published' => 0, 'preserved' => 0];
            $sectionIds = [];
            foreach ($catalog['sections'] as $key => $data) {
                $code = 'seo-'.$key;
                $section = PublicMenuSection::query()->where('code', $code)->first();
                $sectionIds[$key] = $section?->id ?? $this->sections->save($staffId, $data + ['menu' => 'header', 'code' => $code])['id'];
            }
            foreach ($catalog['pages'] as $slug => $data) {
                $page = PublicPage::query()->where('path', $slug)->whereNull('archived_at')->first();
                if ($page === null && PublicPage::query()->where('path', $slug)->exists()) {
                    $counts['preserved'] += count(PublicPage::LOCALES);

                    continue;
                }
                $created = $page === null;
                if ($created) {
                    $page = $this->pages->create($staffId, ['slug' => $slug, 'menu' => $data['menu'], 'menu_section_id' => $sectionIds[$data['section']], 'sort' => $data['sort']]);
                    $this->pages->update($staffId, $page->id, ['menu_labels' => $data['menu_labels']]);
                    $counts['pages_created']++;
                }
                if ($page->kind !== PublicPage::KIND_PAGE || $page->policy_type !== null) {
                    throw new SiteRejected('seo_page_conflict', 409);
                }
                $versions = PublicPageVersion::query()->where('page_id', $page->id)->get();
                foreach ($data['locales'] as $locale => $content) {
                    $history = $versions->where('locale', $locale);
                    if ($created) {
                        $this->pages->saveDraft($staffId, $page->id, $locale, $content);
                        $counts['drafts_created']++;
                    } else {
                        $draft = $history->count() === 1 ? $history->first() : null;
                        if ($draft?->state !== PublicPageVersion::STATE_DRAFT
                            || $draft->title !== $content['title']
                            || $draft->description !== $content['description']
                            || $draft->body !== $this->sanitizer->sanitize($content['body'])) {
                            $counts['preserved']++;

                            continue;
                        }
                    }
                    if ($reason !== null) {
                        $this->pages->publish($staffId, $page->id, $locale, $reason);
                        $counts['published']++;
                    }
                }
            }

            return $counts;
        });
    }
}
