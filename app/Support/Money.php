<?php

namespace App\Support;

use NumberFormatter;

final class Money
{
    /** 123456.5 → "₹1,23,456.50" (Indian digit grouping). */
    public static function inr(float|int|string|null $amount, int $decimals = 2): string
    {
        $amount = (float) ($amount ?? 0);

        if (class_exists(NumberFormatter::class)) {
            $f = new NumberFormatter('en_IN', NumberFormatter::DECIMAL);
            $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

            return '₹'.$f->format($amount);
        }

        // Fallback without intl: lakh/crore grouping by hand.
        $neg = $amount < 0;
        [$int, $frac] = array_pad(explode('.', number_format(abs($amount), $decimals, '.', '')), 2, '');
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $grouped = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3 : $last3;

        return ($neg ? '-' : '').'₹'.$grouped.($decimals ? '.'.$frac : '');
    }

    /** Round to paise, avoiding float drift in totals. */
    public static function round(float $amount): float
    {
        return round($amount + 0.0, 2);
    }
}
