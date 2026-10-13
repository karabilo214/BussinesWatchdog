<?php

namespace App\Support\Site;

use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Legal documents are ordinary pages of the public site marked with a policy type in the owner panel (ADR 0042);
 * the published version of the page is the version of the document. An action that needs consent passes the ids of
 * the versions the person saw; every required document must be accepted in its currently published version (any
 * language), otherwise the action is refused with the current versions. Each consent is stored once, never changed.
 */
class Policies
{
    public const PURPOSE_SIGNUP = 'signup';

    public const PURPOSE_TRIAL = 'trial';

    public const PURPOSE_PURCHASE = 'subscription_purchase';

    public const PURPOSE_PLAN_CHANGE = 'plan_change';

    private const REQUIRED = [
        self::PURPOSE_SIGNUP => [PublicPage::POLICY_SERVICE_TERMS, PublicPage::POLICY_PRIVACY_NOTICE],
        self::PURPOSE_TRIAL => [PublicPage::POLICY_SERVICE_TERMS, PublicPage::POLICY_RECURRING_PAYMENT],
        self::PURPOSE_PURCHASE => [PublicPage::POLICY_SERVICE_TERMS, PublicPage::POLICY_RECURRING_PAYMENT],
        self::PURPOSE_PLAN_CHANGE => [PublicPage::POLICY_SERVICE_TERMS, PublicPage::POLICY_RECURRING_PAYMENT],
    ];

    public function page(string $type): ?PublicPage
    {
        /** @var PublicPage|null $page */
        $page = PublicPage::query()->where('policy_type', $type)->whereNull('archived_at')->first();

        return $page;
    }

    /**
     * The version to show in a language: that language, else English, else any published language.
     */
    public function current(string $type, string $locale): ?PublicPageVersion
    {
        $page = $this->page($type);

        if ($page === null) {
            return null;
        }

        $versions = PublicPageVersion::query()->where('page_id', $page->id)->where('state', PublicPageVersion::STATE_PUBLISHED)->get()->keyBy('locale');

        return $versions[$locale] ?? $versions['en'] ?? $versions->first();
    }

    /**
     * @return list<array{type: string, version_id: string, version: int, locale: string, title: string, url: string, published_at: ?string}>
     */
    public function required(string $purpose, string $locale): array
    {
        $documents = [];

        foreach (self::REQUIRED[$purpose] as $type) {
            $version = $this->current($type, $locale);

            if ($version === null) {
                continue;
            }

            $page = $this->page($type);
            $documents[] = [
                'type' => $type,
                'version_id' => $version->id,
                'version' => $version->version,
                'locale' => $version->locale,
                'title' => $version->title,
                'url' => SiteContent::url($version->locale, (string) $page?->path),
                'published_at' => $version->published_at?->toJSON(),
            ];
        }

        return $documents;
    }

    /**
     * The currently published versions among the ones the person accepted, one per required document; refuses with
     * the current versions when a required document is missing or was accepted in an older version.
     *
     * @param  list<string>  $versionIds
     * @return list<array{0: string, 1: PublicPageVersion}>
     */
    public function verify(string $purpose, array $versionIds, string $locale): array
    {
        $accepted = [];
        $ids = array_values(array_filter($versionIds, fn (mixed $id): bool => is_string($id) && Str::isUuid($id)));

        foreach (self::REQUIRED[$purpose] as $type) {
            $page = $this->page($type);

            if ($page === null || ! PublicPageVersion::query()->where('page_id', $page->id)->where('state', PublicPageVersion::STATE_PUBLISHED)->exists()) {
                continue;
            }

            /** @var PublicPageVersion|null $version */
            $version = PublicPageVersion::query()->where('page_id', $page->id)->where('state', PublicPageVersion::STATE_PUBLISHED)->whereIn('id', $ids)->first();

            if ($version === null) {
                throw new PolicyAcceptanceRequired($this->required($purpose, $locale));
            }

            $accepted[] = [$type, $version];
        }

        return $accepted;
    }

    /**
     * @param  list<array{0: string, 1: PublicPageVersion}>  $accepted
     */
    public function record(string $userId, ?string $tenantId, string $purpose, array $accepted, ?string $contextId = null): void
    {
        if ($contextId !== null && DB::table('policy_acceptances')->where('purpose', $purpose)->where('context_id', $contextId)->exists()) {
            return;
        }

        $now = Carbon::now();

        foreach ($accepted as [$type, $version]) {
            DB::table('policy_acceptances')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'policy_type' => $type,
                'page_version_id' => $version->id,
                'locale' => $version->locale,
                'version' => $version->version,
                'content_hash' => $version->content_hash,
                'purpose' => $purpose,
                'context_id' => $contextId,
                'accepted_at' => $now,
            ]);
        }
    }

    /**
     * @param  list<string>  $versionIds
     */
    public function accept(string $userId, ?string $tenantId, string $purpose, array $versionIds, string $locale, ?string $contextId = null): void
    {
        $this->record($userId, $tenantId, $purpose, $this->verify($purpose, $versionIds, $locale), $contextId);
    }
}
