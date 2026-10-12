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

    public const LOCALES = ['ru', 'en', 'de'];

    protected $fillable = ['kind', 'parent_id', 'slug', 'path', 'menu', 'sort', 'created_by', 'archived_at'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isSystem(): bool
    {
        return $this->kind !== self::KIND_PAGE;
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
