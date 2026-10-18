<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SiteMedia extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'site_media';

    protected $fillable = ['id', 'object_key', 'content_type', 'extension', 'width', 'height', 'bytes', 'sha256', 'original_name', 'uploaded_by', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function path(): string
    {
        return '/media/'.$this->id.'.'.$this->extension;
    }
}
