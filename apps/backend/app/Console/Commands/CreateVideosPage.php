<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\User;
use App\Support\Site\PageAdmin;
use App\Support\Site\SiteRejected;
use Illuminate\Console\Command;

class CreateVideosPage extends Command
{
    protected $signature = 'site:videos-page
        {staff : Email of the platform owner the drafts are saved as}
        {--publish= : Publish the drafts at once with this reason}';

    protected $description = 'Create the editable videos page of the site (slug videos) and save its texts as drafts';

    public function handle(PageAdmin $pages): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('staff'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->first();

        if ($staff === null) {
            $this->error('No active platform owner with this email.');

            return self::FAILURE;
        }

        $reason = $this->option('publish');
        $reason = is_string($reason) ? trim($reason) : null;

        try {
            $page = PublicPage::query()->where('kind', PublicPage::KIND_PAGE)->where('path', 'videos')->whereNull('archived_at')->first()
                ?? $pages->create($staff->id, ['slug' => 'videos', 'menu' => PublicPage::MENU_HEADER, 'sort' => 5]);

            foreach (require database_path('data/videos_page.php') as $locale => $content) {
                $pages->saveDraft($staff->id, $page->id, $locale, $content);

                if ($reason !== null && $reason !== '') {
                    $pages->publish($staff->id, $page->id, $locale, $reason);
                    $this->line("/{$locale}/videos published");
                } else {
                    $this->line("/{$locale}/videos draft saved");
                }
            }
        } catch (SiteRejected $rejected) {
            $this->error($rejected->getMessage());

            return self::FAILURE;
        }

        if ($reason === null || $reason === '') {
            $this->info('Review the drafts in the owner panel (Site → Pages → videos) and publish each language.');
        }

        return self::SUCCESS;
    }
}
