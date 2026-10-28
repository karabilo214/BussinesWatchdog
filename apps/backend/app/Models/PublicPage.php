<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicPage extends Model
{
    use HasUuids;

    public const KIND_HOME = 'home';

    public const KIND_PRICING = 'pricing';

    public const KIND_CONTACT = 'contact';

    public const KIND_PAGE = 'page';

    public const MENU_NONE = 'none';

    public const MENU_HEADER = 'header';

    public const MENU_FOOTER = 'footer';

    public const MENUS = [self::MENU_NONE, self::MENU_HEADER, self::MENU_FOOTER];

    public const FOOTER_GROUPS = ['product', 'company', 'legal'];

    public const LOCALES = ['ru', 'en', 'de'];

    public const MENU_LABEL_MAX = 40;

    public const POLICY_SERVICE_TERMS = 'service_terms';

    public const POLICY_PRIVACY_NOTICE = 'privacy_notice';

    public const POLICY_RECURRING_PAYMENT = 'recurring_payment';

    public const POLICIES = [self::POLICY_SERVICE_TERMS, self::POLICY_PRIVACY_NOTICE, self::POLICY_RECURRING_PAYMENT];

    protected $fillable = ['kind', 'parent_id', 'slug', 'path', 'menu', 'menu_parent_id', 'menu_labels', 'menu_section_id', 'footer_group', 'sort', 'policy_type', 'created_by', 'archived_at'];

    public function footerGroup(): string
    {
        if ($this->footer_group !== null) {
            return $this->footer_group;
        }

        if ($this->policy_type !== null || str_starts_with($this->path, 'legal/') || in_array($this->slug, ['imprint', 'terms', 'privacy', 'subscription', 'refunds', 'refund-policy'], true)) {
            return 'legal';
        }

        return $this->kind === self::KIND_CONTACT || in_array($this->slug, ['about', 'contact'], true) ? 'company' : 'product';
    }

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'menu_labels' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function isSystem(): bool
    {
        return $this->kind !== self::KIND_PAGE;
    }

    public function isEditable(): bool
    {
        return in_array($this->kind, [self::KIND_PAGE, self::KIND_HOME], true);
    }

    /**
     * @return BelongsTo<PublicPage, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PublicPage::class, 'parent_id');
    }

    /**
     * @return HasMany<PublicPageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PublicPageVersion::class, 'page_id');
    }
}
