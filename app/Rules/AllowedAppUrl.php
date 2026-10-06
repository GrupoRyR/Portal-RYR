<?php

namespace App\Rules;

use App\Catalog\AppUrlPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Form rule for the URL of a web app (see App\Catalog\AppUrlPolicy).
 */
class AllowedAppUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! AppUrlPolicy::fromConfig()->allows($value)) {
            $fail('La dirección debe empezar por https:// y pertenecer a un dominio permitido.');
        }
    }
}
