<?php

namespace App\Support;

/**
 * Indian business ID formats.
 */
final class IndianIds
{
    private const GST_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** GST state codes (first two digits of a GSTIN). */
    public const GST_STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand',
        '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya',
        '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '25' => 'Daman and Diu', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra',
        '28' => 'Andhra Pradesh', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu',
        '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
    ];

    /** State name for a GSTIN's state code, or null. */
    public static function gstinState(?string $gstin): ?string
    {
        return self::GST_STATES[substr(strtoupper(trim((string) $gstin)), 0, 2)] ?? null;
    }

    /** Same state, ignoring case, spacing, "&" vs "and" and the "NCT of" prefix. */
    public static function sameState(?string $a, ?string $b): bool
    {
        $n = fn (?string $s) => preg_replace('/^(nct of |the )/', '', preg_replace('/\s+/', ' ', str_replace('&', 'and', strtolower(trim((string) $s)))));

        return $n($a) !== '' && $n($a) === $n($b);
    }

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
