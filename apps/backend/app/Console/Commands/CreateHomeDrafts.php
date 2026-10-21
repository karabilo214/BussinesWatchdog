<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\User;
use App\Support\Site\PageAdmin;
use App\Support\Site\SiteRejected;
use Illuminate\Console\Command;

/**
 * Puts the home page texts (database/data/home_page) into the owner panel as drafts in every language. With --publish
 * the drafts are published at once, each with an audit record; otherwise the owner reviews and publishes them.
 */
class CreateHomeDrafts extends Command
{
    protected $signature = 'site:home-drafts
        {staff : Email of the platform owner the drafts are saved as}
        {--publish= : Publish the drafts at once with this reason}';

    protected $description = 'Save the home page of the site as drafts in the owner panel';

    public function handle(PageAdmin $pages): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('staff'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->first();

        if ($staff === null) {
            $this->error('No active platform owner with this email.');

            return self::FAILURE;
        }

        $page = PublicPage::query()->where('kind', PublicPage::KIND_HOME)->first();

        if ($page === null) {
            $this->error('The home page does not exist.');

            return self::FAILURE;
        }

        $reason = $this->option('publish');
        $reason = is_string($reason) ? trim($reason) : null;
        $locales = require database_path('data/home_page.php');

        foreach ($locales as $locale => $content) {
            try {
                $pages->saveDraft($staff->id, $page->id, $locale, $content);

                if ($reason !== null && $reason !== '') {
                    $pages->publish($staff->id, $page->id, $locale, $reason);
                    $this->line("/{$locale} published");

                    continue;
                }
            } catch (SiteRejected $rejected) {
                $this->error("/{$locale}: {$rejected->getMessage()}");

                return self::FAILURE;
            }

            $this->line("/{$locale} draft saved");
        }

        if ($reason === null || $reason === '') {
            $this->info('Review the drafts in the owner panel (Site → Home) and publish each language.');
        }

        return self::SUCCESS;
    }
}
