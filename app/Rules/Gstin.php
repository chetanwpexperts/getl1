<?php

namespace App\Rules;

use App\Support\IndianIds;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Gstin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! IndianIds::isValidGstin($value)) {
            $fail('Enter a valid 15-character GSTIN (check for typos).');
        }
    }
}
