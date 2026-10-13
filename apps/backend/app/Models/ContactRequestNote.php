<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContactRequestNote extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['contact_request_id', 'staff_id', 'body'];
}
