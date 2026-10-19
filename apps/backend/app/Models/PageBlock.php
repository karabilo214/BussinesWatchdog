<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PageBlock extends Model
{
    use HasUuids;

    public const CATEGORIES = ['hero', 'features', 'steps', 'pricing', 'faq', 'cta', 'testimonials', 'logos', 'comparison', 'stats', 'team', 'gallery', 'contact', 'text', 'other'];

    protected $fillable = ['category', 'name', 'bodies', 'sort', 'created_by', 'updated_by', 'archived_at'];

    protected function casts(): array
    {
        return [
            'bodies' => 'array',
            'sort' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
