<?php

namespace App\Support\Site;

use App\Models\PageBlock;
use App\Models\PublicPage;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;

class PageBlockAdmin
{
    public function __construct(
        private readonly PageSanitizer $sanitizer,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return PageBlock::query()->whereNull('archived_at')->orderBy('sort')->orderBy('name')->get()
            ->map(fn (PageBlock $block): array => $this->card($block))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function block(string $blockId): array
    {
        return $this->card($this->find($blockId));
    }

    /**
     * @param  array{name: string, category: string, sort?: int, bodies?: array<string, ?string>}  $input
     */
    public function create(string $staffId, array $input): PageBlock
    {
        $block = PageBlock::query()->create($this->values($input, null) + ['created_by' => $staffId, 'updated_by' => $staffId]);
        $this->audit->record($staffId, 'site.block.created', 'page_block', $block->id, PlatformAudit::RESULT_SUCCEEDED, $this->summary($block));

        return $block;
    }

    /**
     * @param  array{name?: string, category?: string, sort?: int, bodies?: array<string, ?string>}  $input
     */
    public function update(string $staffId, string $blockId, array $input): PageBlock
    {
        $block = $this->find($blockId);
        $block->forceFill($this->values($input, $block) + ['updated_by' => $staffId])->save();
        $this->audit->record($staffId, 'site.block.updated', 'page_block', $block->id, PlatformAudit::RESULT_SUCCEEDED, $this->summary($block));

        return $block;
    }

    public function archive(string $staffId, string $blockId, string $reason): void
    {
        $block = $this->find($blockId);
        $block->forceFill(['archived_at' => Carbon::now(), 'updated_by' => $staffId])->save();
        $this->audit->record($staffId, 'site.block.archived', 'page_block', $block->id, PlatformAudit::RESULT_SUCCEEDED, ['name' => $block->name], $reason);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(array $input, ?PageBlock $current): array
    {
        $values = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);

            if ($name === '' || mb_strlen($name) > 120) {
                throw new SiteRejected('name_invalid');
            }

            $values['name'] = $name;
        }

        if (array_key_exists('category', $input)) {
            if (! in_array($input['category'], PageBlock::CATEGORIES, true)) {
                throw new SiteRejected('category_invalid');
            }

            $values['category'] = $input['category'];
        }

        if (array_key_exists('sort', $input)) {
            $values['sort'] = (int) $input['sort'];
        }

        $bodies = $current?->bodies ?? [];

        foreach ((array) ($input['bodies'] ?? []) as $locale => $body) {
            if (! in_array($locale, PublicPage::LOCALES, true)) {
                throw new SiteRejected('locale_invalid');
            }

            $clean = $this->sanitizer->sanitize((string) $body);

            if ($clean === '') {
                unset($bodies[$locale]);
            } else {
                $bodies[$locale] = $clean;
            }
        }

        if ($bodies === []) {
            throw new SiteRejected('body_required');
        }

        $values['bodies'] = $bodies;

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(PageBlock $block): array
    {
        return [
            'id' => $block->id,
            'category' => $block->category,
            'name' => $block->name,
            'bodies' => (object) $block->bodies,
            'sort' => $block->sort,
            'updated_at' => ($block->updated_at ?? $block->created_at)?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PageBlock $block): array
    {
        return ['name' => $block->name, 'category' => $block->category, 'sort' => $block->sort, 'locales' => array_keys($block->bodies), 'content_hash' => hash('sha256', json_encode($block->bodies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    private function find(string $blockId): PageBlock
    {
        /** @var PageBlock|null $block */
        $block = PageBlock::query()->whereKey($blockId)->whereNull('archived_at')->first();

        return $block ?? throw new SiteRejected('block_not_found', 404);
    }
}
