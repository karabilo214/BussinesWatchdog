<?php

namespace App\Support\Site;

use App\Models\PlatformStaff;
use App\Models\PublicMenuSection;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pages of the public site edited in the owner panel (ADR 0039): a page has a slug fixed at creation and at most one
 * parent; every language has its own draft and at most one published version; a published version never changes,
 * publishing again retires it and keeps it in the history. The pricing and contact pages are changed only in code.
 */
class PageAdmin
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Top-level addresses served by the site itself. */
    private const RESERVED = ['signup', 'pay'];

    public function __construct(
        private readonly PageSanitizer $sanitizer,
        private readonly PlatformAudit $audit,
        private readonly SiteDefaults $defaults,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function pages(): array
    {
        $pages = PublicPage::query()->whereNull('archived_at')->orderBy('sort')->orderBy('path')->get();
        $versions = PublicPageVersion::query()
            ->whereIn('page_id', $pages->pluck('id'))
            ->whereIn('state', [PublicPageVersion::STATE_DRAFT, PublicPageVersion::STATE_PUBLISHED])
            ->get()
            ->groupBy('page_id');

        return $pages->map(fn (PublicPage $page): array => $this->summary($page, $versions[$page->id] ?? collect()))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function page(string $pageId): array
    {
        $page = $this->find($pageId);
        $versions = PublicPageVersion::query()->where('page_id', $page->id)->orderBy('locale')->orderByDesc('version')->get();
        $staff = PlatformStaff::query()->with('user')->whereIn('id', $versions->pluck('published_by')->merge($versions->pluck('created_by'))->filter()->unique())->get()->keyBy('id');
        $locales = [];

        foreach (PublicPage::LOCALES as $locale) {
            $own = $versions->where('locale', $locale);
            $locales[$locale] = [
                'draft' => ($draft = $own->firstWhere('state', PublicPageVersion::STATE_DRAFT)) === null ? null : $this->content($draft),
                'published' => ($published = $own->firstWhere('state', PublicPageVersion::STATE_PUBLISHED)) === null ? null : $this->content($published),
            ];
        }

        return $this->summary($page, $versions->whereIn('state', [PublicPageVersion::STATE_DRAFT, PublicPageVersion::STATE_PUBLISHED])) + [
            'content' => $locales,
            'builtin' => $page->kind === PublicPage::KIND_HOME ? array_combine(PublicPage::LOCALES, array_map(fn (string $locale): ?array => $this->defaults->content($page, $locale), PublicPage::LOCALES)) : null,
            'history' => $versions->where('state', '!=', PublicPageVersion::STATE_DRAFT)->map(fn (PublicPageVersion $version): array => [
                'id' => $version->id,
                'locale' => $version->locale,
                'version' => $version->version,
                'state' => $version->state,
                'title' => $version->title,
                'published_at' => $version->published_at?->toJSON(),
                'retired_at' => $version->retired_at?->toJSON(),
                'published_by' => $version->published_by === null ? null : $staff->get($version->published_by)?->user?->name,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array{slug: string, parent_id?: ?string, menu?: string, menu_section_id?: ?string, footer_group?: ?string, sort?: int}  $input
     */
    public function create(string $staffId, array $input): PublicPage
    {
        $slug = $input['slug'];

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 60) {
            throw new SiteRejected('slug_invalid');
        }

        $menu = $input['menu'] ?? PublicPage::MENU_NONE;
        $footerGroup = $this->footerGroup($input['footer_group'] ?? null);
        $sectionId = $this->menuSection($input['menu_section_id'] ?? null, $menu);

        if (! in_array($menu, PublicPage::MENUS, true)) {
            throw new SiteRejected('menu_invalid');
        }

        return DB::transaction(function () use ($staffId, $input, $slug, $menu, $footerGroup, $sectionId): PublicPage {
            $parent = null;

            if (($input['parent_id'] ?? null) !== null) {
                /** @var PublicPage|null $parent */
                $parent = PublicPage::query()->whereKey($input['parent_id'])->whereNull('archived_at')->lockForUpdate()->first();

                if ($parent === null || $parent->kind !== PublicPage::KIND_PAGE) {
                    throw new SiteRejected('parent_invalid');
                }

                if ($parent->parent_id !== null) {
                    throw new SiteRejected('too_deep');
                }
            }

            $path = $parent === null ? $slug : $parent->path.'/'.$slug;

            if ($parent === null && in_array($slug, self::RESERVED, true)) {
                throw new SiteRejected('slug_taken', 409);
            }

            if (PublicPage::query()->where('path', $path)->whereNull('archived_at')->exists()) {
                throw new SiteRejected('slug_taken', 409);
            }

            $page = PublicPage::query()->create([
                'kind' => PublicPage::KIND_PAGE,
                'parent_id' => $parent?->id,
                'slug' => $slug,
                'path' => $path,
                'menu' => $menu,
                'footer_group' => $footerGroup,
                'menu_section_id' => $sectionId,
                'sort' => (int) ($input['sort'] ?? 0),
                'created_by' => $staffId,
            ]);
            $this->audit->record($staffId, 'site.page.created', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['path' => $path, 'menu' => $menu, 'footer_group' => $footerGroup, 'menu_section_id' => $sectionId]);

            return $page;
        });
    }

    /**
     * @param  array{menu?: string, menu_section_id?: ?string, footer_group?: ?string, sort?: int, policy_type?: ?string, menu_parent_id?: ?string, menu_labels?: ?array<string, ?string>}  $input
     */
    public function update(string $staffId, string $pageId, array $input): PublicPage
    {
        $page = $this->find($pageId);

        if (isset($input['menu']) && ! in_array($input['menu'], PublicPage::MENUS, true)) {
            throw new SiteRejected('menu_invalid');
        }

        $changes = array_filter(['menu' => $input['menu'] ?? null, 'sort' => isset($input['sort']) ? (int) $input['sort'] : null], fn ($value): bool => $value !== null);

        if (array_key_exists('footer_group', $input)) {
            $changes['footer_group'] = $this->footerGroup($input['footer_group']);
        }

        if (array_key_exists('menu_labels', $input)) {
            $changes['menu_labels'] = $this->menuLabels($input['menu_labels']);
        }

        if (array_key_exists('menu_parent_id', $input)) {
            $changes['menu_parent_id'] = $this->menuParent($page, $input['menu_parent_id'], $changes['menu'] ?? $page->menu);
        } elseif (isset($changes['menu']) && $changes['menu'] !== $page->menu && $page->menu_parent_id !== null) {
            $changes['menu_parent_id'] = null;
        }

        if (isset($changes['menu']) && $changes['menu'] !== $page->menu && PublicPage::query()->where('menu_parent_id', $page->id)->whereNull('archived_at')->exists()) {
            throw new SiteRejected('menu_has_children', 409);
        }

        if (array_key_exists('menu_section_id', $input)) {
            $changes['menu_section_id'] = $this->menuSection($input['menu_section_id'], $changes['menu'] ?? $page->menu);
            $changes['menu_parent_id'] = null;
        } elseif (isset($changes['menu']) && $changes['menu'] !== $page->menu) {
            $changes['menu_section_id'] = null;
        }

        if (array_key_exists('policy_type', $input)) {
            $type = $input['policy_type'];

            if ($type !== null && (! in_array($type, PublicPage::POLICIES, true) || $page->kind !== PublicPage::KIND_PAGE)) {
                throw new SiteRejected('policy_type_invalid');
            }

            if ($type !== null && PublicPage::query()->where('policy_type', $type)->whereNull('archived_at')->whereKeyNot($page->id)->exists()) {
                throw new SiteRejected('policy_taken', 409);
            }

            $changes['policy_type'] = $type;
        }

        $before = array_intersect_key(['menu_labels' => $page->menu_labels] + $page->getAttributes(), $changes);
        $page->forceFill($changes)->save();
        $this->audit->record($staffId, 'site.page.updated', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => $before, 'after' => $changes]);

        return $page;
    }

    /**
     * @return array<string, string>|null
     */
    private function menuLabels(mixed $labels): ?array
    {
        if ($labels === null) {
            return null;
        }

        if (! is_array($labels)) {
            throw new SiteRejected('menu_label_invalid');
        }

        $clean = [];

        foreach ($labels as $locale => $label) {
            if (! in_array($locale, PublicPage::LOCALES, true) || ! (is_string($label) || $label === null)) {
                throw new SiteRejected('menu_label_invalid');
            }

            $label = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? '');

            if (mb_strlen($label) > PublicPage::MENU_LABEL_MAX) {
                throw new SiteRejected('menu_label_invalid');
            }

            if ($label !== '') {
                $clean[$locale] = $label;
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function footerGroup(mixed $group): ?string
    {
        if ($group !== null && ! in_array($group, PublicPage::FOOTER_GROUPS, true)) {
            throw new SiteRejected('footer_group_invalid');
        }

        return $group;
    }

    private function menuSection(?string $id, string $menu): ?string
    {
        if ($id === null) {
            return null;
        }
        $section = PublicMenuSection::query()->find($id);
        if ($section === null) {
            throw new SiteRejected('menu_section_invalid');
        }
        if ($section->menu !== $menu) {
            throw new SiteRejected('menu_section_other_menu');
        }

        return $id;
    }

    private function menuParent(PublicPage $page, mixed $parentId, string $menu): ?string
    {
        if ($parentId === null) {
            return null;
        }

        $parent = is_string($parentId) ? PublicPage::query()->whereKey($parentId)->whereNull('archived_at')->first() : null;

        if ($parent === null || $parent->id === $page->id || $parent->kind === PublicPage::KIND_HOME || $parent->menu_parent_id !== null) {
            throw new SiteRejected('menu_parent_invalid');
        }

        if ($menu === PublicPage::MENU_NONE || $parent->menu !== $menu) {
            throw new SiteRejected('menu_parent_other_menu');
        }

        if (PublicPage::query()->where('menu_parent_id', $page->id)->whereNull('archived_at')->exists()) {
            throw new SiteRejected('menu_too_deep');
        }

        return $parent->id;
    }

    /**
     * @param  array{title: string, description?: ?string, body?: ?string}  $input
     */
    public function saveDraft(string $staffId, string $pageId, string $locale, array $input, bool $recordAudit = true): PublicPageVersion
    {
        $page = $this->editable($pageId);
        $this->locale($locale);
        $content = $this->validatedContent($page, $input);

        return DB::transaction(function () use ($staffId, $page, $locale, $content, $recordAudit): PublicPageVersion {
            $this->lock($page);
            /** @var PublicPageVersion|null $draft */
            $draft = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_DRAFT)->first();
            $before = $draft === null ? ['title' => null, 'description' => null, 'body' => null] : $draft->only(['title', 'description', 'body']);

            if ($draft !== null) {
                $draft->forceFill($content + ['created_by' => $staffId])->save();

                if ($recordAudit) {
                    $this->audit->record($staffId, 'site.page.draft_saved', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['locale' => $locale, 'version' => $draft->version, 'before' => $before, 'after' => array_intersect_key($content, $before)]);
                }

                return $draft;
            }

            $draft = PublicPageVersion::query()->create($content + [
                'page_id' => $page->id,
                'locale' => $locale,
                'version' => $this->nextVersion($page, $locale),
                'state' => PublicPageVersion::STATE_DRAFT,
                'created_by' => $staffId,
            ]);
            if ($recordAudit) {
                $this->audit->record($staffId, 'site.page.draft_saved', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['locale' => $locale, 'version' => $draft->version, 'before' => $before, 'after' => array_intersect_key($content, $before)]);
            }

            return $draft;
        });
    }

    /**
     * @param  array{title: string, description?: ?string, body?: ?string}  $input
     */
    public function updatePublished(string $staffId, string $pageId, string $locale, array $input, string $reason): PublicPageVersion
    {
        $page = $this->editable($pageId);
        $this->locale($locale);

        if ($page->policy_type !== null) {
            throw new SiteRejected('legal_page_needs_version', 409);
        }

        $content = $this->validatedContent($page, $input);

        return DB::transaction(function () use ($staffId, $page, $locale, $content, $reason): PublicPageVersion {
            $this->lock($page);
            /** @var PublicPageVersion|null $published */
            $published = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_PUBLISHED)->lockForUpdate()->first();

            if ($published === null) {
                throw new SiteRejected('not_published', 409);
            }

            $before = $published->only(['title', 'description', 'body', 'content_hash']);
            $published->forceFill($content)->save();
            $this->audit->record($staffId, 'site.page.published_updated', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['path' => $page->path, 'locale' => $locale, 'version' => $published->version, 'before' => $before, 'after' => $content], $reason);

            return $published;
        });
    }

    public function discardDraft(string $staffId, string $pageId, string $locale): void
    {
        $page = $this->find($pageId);
        $this->locale($locale);
        $deleted = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_DRAFT)->delete();

        if ($deleted > 0) {
            $this->audit->record($staffId, 'site.page.draft_discarded', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['locale' => $locale, 'before' => ['state' => PublicPageVersion::STATE_DRAFT], 'after' => ['state' => 'deleted']]);
        }
    }

    public function publish(string $staffId, string $pageId, string $locale, string $reason): PublicPageVersion
    {
        $page = $this->editable($pageId);
        $this->locale($locale);

        return DB::transaction(function () use ($staffId, $page, $locale, $reason): PublicPageVersion {
            $this->lock($page);
            /** @var PublicPageVersion|null $draft */
            $draft = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_DRAFT)->lockForUpdate()->first();

            if ($draft === null) {
                throw new SiteRejected('no_draft', 409);
            }

            $now = Carbon::now();
            $previous = $this->retirePublished($page, $locale, $now);
            $draft->forceFill(['state' => PublicPageVersion::STATE_PUBLISHED, 'published_at' => $now, 'published_by' => $staffId])->save();
            $this->audit->record($staffId, 'site.page.published', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['path' => $page->path, 'locale' => $locale, 'version' => $draft->version, 'previous_version' => $previous, 'content_hash' => $draft->content_hash, 'before' => ['state' => PublicPageVersion::STATE_DRAFT], 'after' => ['state' => $draft->state]], $reason);

            return $draft;
        });
    }

    public function unpublish(string $staffId, string $pageId, string $locale, string $reason): void
    {
        $page = $this->find($pageId);
        $this->locale($locale);

        DB::transaction(function () use ($staffId, $page, $locale, $reason): void {
            $this->lock($page);
            $previous = $this->retirePublished($page, $locale, Carbon::now());

            if ($previous === null) {
                throw new SiteRejected('not_published', 409);
            }

            $this->audit->record($staffId, 'site.page.unpublished', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['path' => $page->path, 'locale' => $locale, 'version' => $previous, 'before' => ['state' => PublicPageVersion::STATE_PUBLISHED], 'after' => ['state' => PublicPageVersion::STATE_RETIRED]], $reason);
        });
    }

    public function restore(string $staffId, string $pageId, string $versionId): PublicPageVersion
    {
        $page = $this->editable($pageId);
        /** @var PublicPageVersion|null $source */
        $source = PublicPageVersion::query()->where('page_id', $page->id)->whereKey($versionId)->first();

        if ($source === null) {
            throw new SiteRejected('version_not_found', 404);
        }

        $previousDraft = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $source->locale)->where('state', PublicPageVersion::STATE_DRAFT)->first();
        $before = $previousDraft === null ? ['title' => null, 'description' => null, 'body' => null] : $previousDraft->only(['title', 'description', 'body']);
        $draft = $this->saveDraft($staffId, $page->id, $source->locale, ['title' => $source->title, 'description' => $source->description, 'body' => $source->body], false);
        $this->audit->record($staffId, 'site.page.restored', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['locale' => $source->locale, 'from_version' => $source->version, 'draft_version' => $draft->version, 'before' => $before, 'after' => $draft->only(['title', 'description', 'body'])]);

        return $draft;
    }

    public function deleteVersion(string $staffId, string $pageId, string $versionId, string $reason): void
    {
        $page = $this->find($pageId);

        DB::transaction(function () use ($staffId, $page, $versionId, $reason): void {
            $this->lock($page);
            /** @var PublicPageVersion|null $version */
            $version = PublicPageVersion::query()->where('page_id', $page->id)->whereKey($versionId)->lockForUpdate()->first();

            if ($version === null) {
                throw new SiteRejected('version_not_found', 404);
            }

            if ($version->state !== PublicPageVersion::STATE_RETIRED) {
                throw new SiteRejected('version_not_retired', 409);
            }

            if ($page->policy_type !== null || DB::table('policy_acceptances')->where('page_version_id', $version->id)->exists()) {
                throw new SiteRejected('version_is_legal', 409);
            }

            $version->delete();
            $this->audit->record($staffId, 'site.page.version_deleted', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['locale' => $version->locale, 'version' => $version->version, 'content_hash' => $version->content_hash, 'before' => ['state' => PublicPageVersion::STATE_RETIRED], 'after' => ['state' => 'deleted']], $reason);
        });
    }

    public function archive(string $staffId, string $pageId, string $reason): void
    {
        $page = $this->find($pageId);

        if ($page->isSystem()) {
            throw new SiteRejected('system_page', 409);
        }

        DB::transaction(function () use ($staffId, $page, $reason): void {
            $this->lock($page);

            if (PublicPage::query()->where('parent_id', $page->id)->whereNull('archived_at')->exists()) {
                throw new SiteRejected('has_children', 409);
            }

            $now = Carbon::now();

            foreach (PublicPage::LOCALES as $locale) {
                $this->retirePublished($page, $locale, $now);
            }

            PublicPageVersion::query()->where('page_id', $page->id)->where('state', PublicPageVersion::STATE_DRAFT)->delete();
            PublicPage::query()->where('menu_parent_id', $page->id)->update(['menu_parent_id' => null]);
            $previousPolicy = $page->policy_type;
            $page->forceFill(['archived_at' => $now, 'policy_type' => null])->save();
            $this->audit->record($staffId, 'site.page.archived', 'public_page', $page->id, PlatformAudit::RESULT_SUCCEEDED, ['path' => $page->path, 'before' => ['archived_at' => null, 'policy_type' => $previousPolicy], 'after' => ['archived_at' => $now->toJSON(), 'policy_type' => null]], $reason);
        });
    }

    public static function hash(string $title, string $description, string $body): string
    {
        return hash('sha256', json_encode([$title, $description, $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  iterable<PublicPageVersion>  $versions
     * @return array<string, mixed>
     */
    private function summary(PublicPage $page, iterable $versions): array
    {
        $locales = array_fill_keys(PublicPage::LOCALES, ['published' => null, 'draft' => false, 'title' => null]);

        foreach ($versions as $version) {
            if ($version->state === PublicPageVersion::STATE_PUBLISHED) {
                $locales[$version->locale]['published'] = $version->published_at?->toJSON();
                $locales[$version->locale]['title'] ??= $version->title;
            } else {
                $locales[$version->locale]['draft'] = true;
                $locales[$version->locale]['title'] = $version->title;
            }
        }

        return [
            'id' => $page->id,
            'kind' => $page->kind,
            'parent_id' => $page->parent_id,
            'slug' => $page->slug,
            'path' => $page->path,
            'menu' => $page->menu,
            'footer_group' => $page->footer_group,
            'menu_section_id' => $page->menu_section_id,
            'menu_parent_id' => $page->menu_parent_id,
            'menu_labels' => (object) ($page->menu_labels ?? []),
            'sort' => $page->sort,
            'policy_type' => $page->policy_type,
            'editable' => $page->isEditable(),
            'created_at' => $page->created_at?->toJSON(),
            'locales' => $locales,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(PublicPageVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'title' => $version->title,
            'description' => $version->description,
            'body' => $version->body,
            'content_hash' => $version->content_hash,
            'updated_at' => ($version->updated_at ?? $version->created_at)?->toJSON(),
            'published_at' => $version->published_at?->toJSON(),
        ];
    }

    private function retirePublished(PublicPage $page, string $locale, Carbon $now): ?int
    {
        /** @var PublicPageVersion|null $published */
        $published = PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->where('state', PublicPageVersion::STATE_PUBLISHED)->lockForUpdate()->first();

        if ($published === null) {
            return null;
        }

        $published->forceFill(['state' => PublicPageVersion::STATE_RETIRED, 'retired_at' => $now])->save();

        return $published->version;
    }

    private function nextVersion(PublicPage $page, string $locale): int
    {
        return (int) PublicPageVersion::query()->where('page_id', $page->id)->where('locale', $locale)->max('version') + 1;
    }

    private function find(string $pageId): PublicPage
    {
        /** @var PublicPage|null $page */
        $page = PublicPage::query()->whereKey($pageId)->whereNull('archived_at')->first();

        return $page ?? throw new SiteRejected('page_not_found', 404);
    }

    /**
     * @param  array{title: string, description?: ?string, body?: ?string}  $input
     * @return array{title: string, description: ?string, body: string, content_hash: string}
     */
    private function validatedContent(PublicPage $page, array $input): array
    {
        $title = trim($input['title']);
        $description = trim((string) ($input['description'] ?? ''));
        $body = $this->sanitizer->sanitize((string) ($input['body'] ?? ''));

        if ($title === '' || mb_strlen($title) > 200) {
            throw new SiteRejected('title_invalid');
        }

        if (mb_strlen($description) > 300) {
            throw new SiteRejected('description_invalid');
        }

        if ($body === '' && $page->kind === PublicPage::KIND_PAGE) {
            throw new SiteRejected('body_required');
        }

        return ['title' => $title, 'description' => $description === '' ? null : $description, 'body' => $body, 'content_hash' => self::hash($title, $description, $body)];
    }

    public function editable(string $pageId): PublicPage
    {
        $page = $this->find($pageId);

        return $page->isEditable() ? $page : throw new SiteRejected('edited_in_code', 409);
    }

    private function lock(PublicPage $page): void
    {
        PublicPage::query()->whereKey($page->id)->lockForUpdate()->first();
    }

    private function locale(string $locale): void
    {
        if (! in_array($locale, PublicPage::LOCALES, true)) {
            throw new SiteRejected('locale_invalid');
        }
    }
}
