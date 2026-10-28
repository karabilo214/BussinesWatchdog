<?php

namespace App\Support\Site;

use App\Models\PublicMenuSection;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Later releases never replace editorial changes or restore removed publications: a published page is refreshed only while
 * it still equals a version an earlier release shipped (`data/seo/shipped.php`) and has no draft.
 */
class SeoPageImporter
{
    public function __construct(
        private readonly PageAdmin $pages,
        private readonly MenuSectionAdmin $sections,
        private readonly PageSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, array<string, list<string>>>|null  $shipped
     */
    public function import(string $staffId, ?string $reason, ?array $shipped = null): array
    {
        $catalog = require database_path('data/seo/catalog.php');
        $shipped ??= require database_path('data/seo/shipped.php');

        return DB::transaction(function () use ($staffId, $reason, $catalog, $shipped): array {
            $counts = ['pages_created' => 0, 'drafts_created' => 0, 'published' => 0, 'refreshed' => 0, 'preserved' => 0];
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
                    } elseif ($this->refreshable($history, $shipped[$slug][$locale] ?? [], $content)) {
                        if ($reason === null) {
                            $counts['preserved']++;

                            continue;
                        }
                        $this->pages->saveDraft($staffId, $page->id, $locale, $content);
                        $this->pages->publish($staffId, $page->id, $locale, $reason);
                        $counts['refreshed']++;

                        continue;
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

    /**
     * @param  Collection<int, PublicPageVersion>  $history
     * @param  list<string>  $shippedHashes
     * @param  array{title: string, description: string, body: string}  $content
     */
    private function refreshable(Collection $history, array $shippedHashes, array $content): bool
    {
        $published = $history->firstWhere('state', PublicPageVersion::STATE_PUBLISHED);

        return $published !== null
            && $history->where('state', PublicPageVersion::STATE_DRAFT)->isEmpty()
            && in_array($published->content_hash, $shippedHashes, true)
            && $published->content_hash !== PageAdmin::hash(trim($content['title']), trim($content['description']), $this->sanitizer->sanitize($content['body']));
    }
}
