<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PublicMenuSection extends Model
{
    use HasUuids;

    protected $fillable = ['menu', 'labels', 'sort'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'sort' => 'integer'];
    }
}
