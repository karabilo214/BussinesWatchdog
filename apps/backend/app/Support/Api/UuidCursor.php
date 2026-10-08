<?php

namespace App\Support\Api;

use Illuminate\Support\Str;

class UuidCursor
{
    public static function encode(string $id): string
    {
        return base64_encode($id);
    }

    public static function decode(string $cursor): ?string
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! Str::isUuid($decoded)) {
            return null;
        }

        return $decoded;
    }
}
