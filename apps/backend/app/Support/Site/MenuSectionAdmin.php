<?php

namespace App\Support\Site;

use App\Models\PublicMenuSection;
use App\Models\PublicPage;
use App\Support\Platform\PlatformAudit;

class MenuSectionAdmin
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function sections(): array
    {
        return PublicMenuSection::query()->orderBy('sort')->orderBy('id')->get()->map(fn ($section) => $this->summary($section))->all();
    }

    public function save(string $staffId, array $input, ?string $id = null): array
    {
        $section = $id === null ? new PublicMenuSection : PublicMenuSection::query()->findOrFail($id);
        $before = $section->only(['labels', 'sort']);
        if ($id === null) {
            $section->menu = $input['menu'];
            // Internal imports may give a section a stable identity; the public API does not accept this field.
            $section->code = $input['code'] ?? null;
        }
        if (array_key_exists('labels', $input)) {
            $labels = [];
            foreach (PublicPage::LOCALES as $locale) {
                $label = trim(preg_replace('/\s+/u', ' ', $input['labels'][$locale] ?? '') ?? '');
                if ($label === '' || mb_strlen($label) > PublicPage::MENU_LABEL_MAX) {
                    throw new SiteRejected('menu_label_invalid');
                }
                $labels[$locale] = $label;
            }
            $section->labels = $labels;
        }
        $section->sort = $input['sort'] ?? $section->sort ?? 0;
        $section->save();
        $this->audit->record($staffId, $id === null ? 'site.menu_section.created' : 'site.menu_section.updated', 'public_menu_section', $section->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => $id === null ? [] : $before, 'after' => $section->only(['labels', 'sort'])]);

        return $this->summary($section);
    }

    private function summary(PublicMenuSection $section): array
    {
        return $section->only(['id', 'menu', 'labels', 'sort', 'footer_key']);
    }
}
