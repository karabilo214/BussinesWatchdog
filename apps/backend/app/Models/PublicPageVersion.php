<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicPageVersion extends Model
{
    use HasUuids;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_RETIRED = 'retired';

    protected $fillable = ['page_id', 'locale', 'version', 'title', 'description', 'body', 'content_hash', 'state', 'created_by', 'published_by', 'published_at', 'retired_at'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PublicPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(PublicPage::class, 'page_id');
    }
}
