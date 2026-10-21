<?php

namespace App\Support\Checkout;

use App\Models\Store;
use Illuminate\Validation\ValidationException;

/**
 * The checkout pages and elements of a store with a third-party theme (ADR 0075): used by the browser check and by
 * the Shopware app's observation script. An empty field means the Shopware default. Only data — paths and CSS
 * selectors — never code.
 */
class CheckoutLayout
{
    public const PLATFORMS = ['shopware'];

    public const PATHS = ['confirm_path', 'finish_path'];

    public const SELECTORS = ['order_form', 'submit_button', 'payment_method', 'error_notice'];

    public const DEFAULTS = [
        'confirm_path' => '/checkout/confirm',
        'finish_path' => '/checkout/finish',
        'order_form' => '#confirmOrderForm',
        'submit_button' => '#confirmFormSubmit',
        'payment_method' => '.payment-method-input',
        'error_notice' => '.alert-danger',
    ];

    public const MAX_LENGTH = 200;

    private const PATH_PATTERN = '{^/[A-Za-z0-9\-._~%/]*$}';

    private const SELECTOR_PATTERN = '{^[A-Za-z0-9 #.\-_\[\]=:"\'>~+*,()^$|]+$}';

    public static function supports(Store $store): bool
    {
        return in_array($store->platform, self::PLATFORMS, true);
    }

    /**
     * @return array<string, string> the fields the customer set
     */
    public static function custom(Store $store): array
    {
        $layout = $store->settings['checkout_layout'] ?? [];

        $custom = [];

        foreach (array_keys(self::DEFAULTS) as $field) {
            if (is_array($layout) && is_string($layout[$field] ?? null) && $layout[$field] !== '') {
                $custom[$field] = $layout[$field];
            }
        }

        return $custom;
    }

    /**
     * @return array<string, string> every field, the customer's value or the default
     */
    public static function effective(Store $store): array
    {
        return self::custom($store) + self::DEFAULTS;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string> the non-empty fields, trimmed
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $layout = [];

        foreach (array_keys(self::DEFAULTS) as $field) {
            $value = $input[$field] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (! is_string($value) || mb_strlen(trim($value)) > self::MAX_LENGTH) {
                $errors[$field] = 'checkout_layout_too_long';

                continue;
            }

            $value = trim($value);
            $valid = in_array($field, self::PATHS, true)
                ? strlen($value) > 1 && preg_match(self::PATH_PATTERN, $value) === 1 && ! str_contains($value, '..') && ! str_contains($value, '//')
                : preg_match(self::SELECTOR_PATTERN, $value) === 1;

            if (! $valid) {
                $errors[$field] = in_array($field, self::PATHS, true) ? 'checkout_layout_path_invalid' : 'checkout_layout_selector_invalid';

                continue;
            }

            $layout[$field] = in_array($field, self::PATHS, true) ? rtrim($value, '/') : $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $code): array => [$code], $errors));
        }

        return $layout;
    }
}
