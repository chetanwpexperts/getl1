<?php

namespace App\Rules;

use App\Support\IndianIds;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Pan implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! IndianIds::isValidPan($value)) {
            $fail('Enter a valid 10-character PAN, e.g. AAPFU0939F.');
        }
    }
}
