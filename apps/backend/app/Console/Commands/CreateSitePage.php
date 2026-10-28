<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\User;
use App\Support\Site\PageAdmin;
use App\Support\Site\SiteRejected;
use Illuminate\Console\Command;

class CreateSitePage extends Command
{
    protected $signature = 'site:page
        {slug : Page from database/data/pages, e.g. videos or security}
        {staff : Email of the platform owner the drafts are saved as}
        {--publish= : Publish the drafts at once with this reason}
        {--menu : Only apply the menu labels from the data file; texts stay untouched}';

    protected $description = 'Create an editable page of the site from database/data/pages and save its texts as drafts';

    public function handle(PageAdmin $pages): int
    {
        $slug = (string) $this->argument('slug');
        $file = database_path("data/pages/{$slug}.php");

        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1 || ! is_file($file)) {
            $this->error('No page data for this slug in database/data/pages.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('staff'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->first();

        if ($staff === null) {
            $this->error('No active platform owner with this email.');

            return self::FAILURE;
        }

        $reason = $this->option('publish');
        $reason = is_string($reason) ? trim($reason) : null;
        $data = require $file;

        try {
            $page = PublicPage::query()->where('kind', PublicPage::KIND_PAGE)->where('path', $slug)->whereNull('archived_at')->first()
                ?? $pages->create($staff->id, ['slug' => $slug, 'menu' => $data['menu'], 'sort' => $data['sort']]);

            if (isset($data['menu_labels'])) {
                $pages->update($staff->id, $page->id, ['menu_labels' => $data['menu_labels']]);
                $this->line("/{$slug} menu labels set");
            }

            if ($this->option('menu')) {
                return self::SUCCESS;
            }

            foreach ($data['locales'] as $locale => $content) {
                $pages->saveDraft($staff->id, $page->id, $locale, $content);

                if ($reason !== null && $reason !== '') {
                    $pages->publish($staff->id, $page->id, $locale, $reason);
                    $this->line("/{$locale}/{$slug} published");
                } else {
                    $this->line("/{$locale}/{$slug} draft saved");
                }
            }
        } catch (SiteRejected $rejected) {
            $this->error($rejected->getMessage());

            return self::FAILURE;
        }

        if ($reason === null || $reason === '') {
            $this->info("Review the drafts in the owner panel (Site → Pages → {$slug}) and publish each language.");
        }

        return self::SUCCESS;
    }
}
