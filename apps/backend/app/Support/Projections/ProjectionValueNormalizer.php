<?php

namespace App\Support\Projections;

class ProjectionValueNormalizer
{
    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function nullableDateTime(mixed $value): ?string
    {
        return is_string($value) && strtotime($value) !== false ? $value : null;
    }
}
