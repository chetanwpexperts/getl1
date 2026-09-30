<?php

namespace App\Support;

/**
 * Indian business ID formats.
 */
final class IndianIds
{
    private const GST_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 15 chars: 2-digit state, 10-char PAN, entity no., 'Z', check char. */
    public static function isValidGstin(?string $gstin): bool
    {
        $gstin = strtoupper(trim((string) $gstin));

        if (! preg_match('/^(0[1-9]|[1-3][0-9])[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            return false;
        }

        return $gstin[14] === self::gstinCheckChar(substr($gstin, 0, 14));
    }

    /** Official GSTIN check-character algorithm (Luhn mod 36). */
    public static function gstinCheckChar(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $value = strpos(self::GST_CHARS, $first14[$i]);
            $product = $value * (($i % 2) + 1);
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return self::GST_CHARS[(36 - ($sum % 36)) % 36];
    }

    /** ABCDE1234F. The 4th char is the holder type (P person, C company, F firm ...). */
    public static function isValidPan(?string $pan): bool
    {
        return (bool) preg_match('/^[A-Z]{3}[ABCFGHLJPT][A-Z][0-9]{4}[A-Z]$/', strtoupper(trim((string) $pan)));
    }

    /** UDYAM-XX-00-0000000 */
    public static function isValidUdyam(?string $udyam): bool
    {
        return (bool) preg_match('/^UDYAM-[A-Z]{2}-[0-9]{2}-[0-9]{7}$/', strtoupper(trim((string) $udyam)));
    }

    /** The PAN embedded in a GSTIN (chars 3–12). */
    public static function panFromGstin(string $gstin): string
    {
        return substr(strtoupper($gstin), 2, 10);
    }
}
