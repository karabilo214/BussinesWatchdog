<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContactRequestReply extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TEMPLATES = ['answer', 'more_details', 'resolved'];

    protected $fillable = ['contact_request_id', 'staff_id', 'template', 'subject', 'body', 'sent_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
