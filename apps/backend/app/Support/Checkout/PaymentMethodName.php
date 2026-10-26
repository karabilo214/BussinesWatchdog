<?php

namespace App\Support\Checkout;

/** A store's display label; never a gateway identifier or financial evidence. */
class PaymentMethodName
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $name) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
