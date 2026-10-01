<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238: SHA-1, 6 digits, 30 seconds), as used by
 * Google Authenticator, Microsoft Authenticator and similar apps. No external package.
 *
 * - The secret is stored encrypted on the user (cast 'encrypted').
 * - A code is accepted once only (replay protection per user and time step).
 * - Recovery codes are stored hashed and each works once.
 */
class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public function newSecret(): string
    {
        return $this->base32(random_bytes(20));
    }

    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = 'GetL1 Admin';

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period='.self::PERIOD;
    }

    /** Checks a 6-digit code against the secret, allowing one step of clock drift each way. */
    public function verify(User $user, string $secret, string $code, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $step = intdiv($now ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $drift) {
            if (hash_equals($this->code($secret, $step + $drift), $code)) {
                // Each step's code works once per user.
                return Cache::add('2fa-used:'.$user->id.':'.($step + $drift), true, self::PERIOD * 4);
            }
        }

        return false;
    }

    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), $this->unbase32($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** @return array{plain: list<string>, hashed: list<string>} */
    public function newRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < 8; $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashed' => array_map(fn ($c) => Hash::make($c), $plain)];
    }

    /** Uses up a recovery code if it matches. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = (array) json_decode((string) $user->two_factor_recovery_codes, true);
        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($codes))])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count((array) json_decode((string) $user->two_factor_recovery_codes, true));
    }

    private function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function unbase32(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
