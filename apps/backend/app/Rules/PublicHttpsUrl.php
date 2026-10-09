<?php

namespace App\Rules;

use App\Support\Network\PublicDestinationPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(PublicDestinationPolicy::class)->isAllowedUrl($value)) {
            $fail('validation.public_https_url')->translate(['attribute' => $attribute]);
        }
    }
}
