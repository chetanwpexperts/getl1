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

    /** 123456.5 → "Rupees One Lakh Twenty-Three Thousand Four Hundred Fifty-Six and Fifty Paise Only". */
    public static function words(float|int|string $amount): string
    {
        $amount = round((float) $amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $text = 'Rupees '.($rupees === 0 ? 'Zero' : self::indianWords($rupees));
        if ($paise > 0) {
            $text .= ' and '.self::indianWords($paise).' Paise';
        }

        return $text.' Only';
    }

    private static function indianWords(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $count = intdiv($n, $unit);
                // Above 99 crore, the crore count itself is spelled in Indian words.
                $parts[] = ($unit === 10000000 ? self::indianWords($count) : self::belowHundred($count)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($n < 20) {
            return $ones[$n];
        }

        return $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
    }
}
