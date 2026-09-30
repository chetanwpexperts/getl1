<?php

namespace App\Rules;

use App\Support\IndianIds;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Udyam implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! IndianIds::isValidUdyam($value)) {
            $fail('Enter a valid Udyam number, e.g. UDYAM-PB-01-0012345.');
        }
    }
}
