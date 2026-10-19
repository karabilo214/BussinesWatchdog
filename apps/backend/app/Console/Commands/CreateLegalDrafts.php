<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\User;
use App\Support\Site\PageAdmin;
use App\Support\Site\SiteRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Puts the starting texts of the legal pages (terms, privacy, subscription and refunds, legal notice) into the owner
 * panel as drafts in every language: creates the footer pages that do not exist yet and marks the document type.
 * Nothing is published; the owner reviews and publishes each page in the panel. Existing published versions stay.
 */
class CreateLegalDrafts extends Command
{
    protected $signature = 'site:legal-drafts
        {staff : Email of the platform owner the drafts are saved as}
        {--name= : Legal name of the provider, as in the identity documents}
        {--address= : Postal address of the provider, parts separated by commas}
        {--email=hello@business-watchdog.com : Contact email shown in the documents}';

    protected $description = 'Save the legal pages of the site as drafts in the owner panel';

    public function handle(PageAdmin $pages): int
    {
        $name = trim((string) $this->option('name'));
        $address = trim((string) $this->option('address'));
        $email = trim((string) $this->option('email'));

        if ($name === '' || $address === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--name, --address and a valid --email are required.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('staff'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->first();

        if ($staff === null) {
            $this->error('No active platform owner with this email.');

            return self::FAILURE;
        }

        $documents = require database_path('data/legal_pages.php');

        foreach ($documents as $document) {
            $taken = $document['policy_type'] === null ? null : PublicPage::query()->where('policy_type', $document['policy_type'])->whereNull('archived_at')->where('path', '!=', $document['slug'])->value('path');

            if ($taken !== null) {
                $this->error("The document type {$document['policy_type']} is already set on the page /{$taken}; remove it there first.");

                return self::FAILURE;
            }
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn (string $part): bool => $part !== ''));

        foreach ($documents as $document) {
            $page = PublicPage::query()->where('path', $document['slug'])->whereNull('archived_at')->first()
                ?? $pages->create($staff->id, ['slug' => $document['slug'], 'menu' => PublicPage::MENU_FOOTER, 'sort' => $document['sort']]);

            if ($page->kind !== PublicPage::KIND_PAGE) {
                $this->error("/{$document['slug']} is a system page.");

                return self::FAILURE;
            }

            if ($document['policy_type'] !== null && $page->policy_type !== $document['policy_type']) {
                $pages->update($staff->id, $page->id, ['policy_type' => $document['policy_type']]);
            }

            foreach ($document['locales'] as $locale => $content) {
                $values = [
                    ':seller_address_lines' => implode('<br>', array_map('e', $parts)),
                    ':seller_address' => e(implode(', ', $parts)),
                    ':seller_name' => e($name),
                    ':contact_email' => e($email),
                    ':effective_date' => Carbon::now()->locale($locale)->isoFormat('LL'),
                    ':locale' => $locale,
                ];

                try {
                    $pages->saveDraft($staff->id, $page->id, $locale, [
                        'title' => $content['title'],
                        'description' => $content['description'],
                        'body' => strtr($content['body'], $values),
                    ]);
                } catch (SiteRejected $rejected) {
                    $this->error("/{$locale}/{$document['slug']}: {$rejected->getMessage()}");

                    return self::FAILURE;
                }

                $this->line("/{$locale}/{$document['slug']} draft saved");
            }
        }

        $this->info('Review the drafts in the owner panel (Site → Pages) and publish each language.');

        return self::SUCCESS;
    }
}
