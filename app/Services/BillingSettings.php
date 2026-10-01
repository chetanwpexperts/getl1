<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GST and the seller details printed on receipts and invoices, edited in Admin → Billing & GST.
 *
 * Saved values are laid over config('billing.*') at boot, on every request and before each queued
 * job, like the website settings. Nothing saved means the server's .env values (or safe defaults).
 * Empty seller name, address and email fall back to the website's legal name, address and email.
 */
class BillingSettings
{
    private const CACHE_KEY = 'billing_settings:v1';
    private const PREFIX = 'billing.';

    /** field => [config key, type, label, help] */
    public const FIELDS = [
        'gst_enabled' => ['billing.gst_enabled', 'bool', 'Charge GST', 'Only when GetL1 is GST-registered. Prices then show "+ GST" and payments get a tax invoice.'],
        'gstin' => ['billing.seller.gstin', 'gstin', 'GSTIN', '15 characters, from your GST registration certificate.'],
        'gst_rate' => ['billing.gst_rate', 'rate', 'GST rate', 'Software services are usually 18%. Confirm with your CA.'],
        'sac' => ['billing.seller.sac', 'sac', 'SAC code', 'Service code printed on tax invoices, e.g. 998314. Confirm with your CA.'],
        'seller_name' => ['billing.seller.name', 'text', 'Billing name', 'Exactly as on your PAN or GST registration. Empty uses the registered business name from Website.'],
        'seller_address' => ['billing.seller.address', 'textarea', 'Billing address', 'Empty uses the registered address from Website.'],
        'seller_email' => ['billing.seller.email', 'email', 'Billing email', 'Shown on receipts for payment questions. Empty uses the support email.'],
    ];

    public const RATES = [5, 12, 18, 28];

    public function saved(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $values = DB::table('platform_settings')->where('key', 'like', self::PREFIX.'%')->pluck('value', 'key')
                ->mapWithKeys(fn ($v, $k) => [Str::after($k, self::PREFIX) => json_decode($v, true)])->all();
        } catch (\Throwable) {
            return [];
        }
        Cache::put(self::CACHE_KEY, $values, 300);

        return $values;
    }

    /**
     * Lays saved values over config('billing.*'). Only fields saved in admin are touched; the .env value of
     * each is remembered on first run (config billing._defaults) so clearing a field brings it back.
     */
    public function apply(): void
    {
        $defaults = config('billing._defaults');
        if (! is_array($defaults)) {
            $defaults = [];
            foreach (self::FIELDS as [$key]) {
                $defaults[$key] = config($key);
            }
            $defaults['billing.seller.state_code'] = config('billing.seller.state_code');
            config(['billing._defaults' => $defaults]);
        }
        $overlaid = (array) config('billing._overlaid', []);
        foreach ($this->saved() as $field => $value) {
            if (! isset(self::FIELDS[$field])) {
                continue;
            }
            $key = self::FIELDS[$field][0];
            config([$key => $value === null || $value === '' ? $defaults[$key] : $value]);
            $overlaid[$key] = true;
        }
        config(['billing._overlaid' => $overlaid]);

        config([
            'billing.gst_enabled' => (bool) config('billing.gst_enabled'),
            'billing.gst_rate' => (float) config('billing.gst_rate'),
            'billing.seller.name' => config('billing.seller.name') ?: (config('site.legal_name') ?: config('site.name')),
            'billing.seller.address' => config('billing.seller.address') ?: config('site.address'),
            'billing.seller.email' => config('billing.seller.email') ?: config('site.email'),
        ]);
        // Intra-state (CGST + SGST) vs inter-state (IGST) is decided by the state code in our GSTIN.
        $gstin = (string) config('billing.seller.gstin');
        config(['billing.seller.state_code' => $gstin !== '' ? substr($gstin, 0, 2) : $defaults['billing.seller.state_code']]);
    }

    /** What the admin form shows: saved values, else the values currently in force. */
    public function current(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$key]) {
            $out[$field] = config($key);
        }

        return $out;
    }

    public static function rules(): array
    {
        return [
            'gst_enabled' => ['nullable', 'boolean'],
            'gstin' => ['nullable', 'string', 'size:15', function ($attr, $value, $fail) {
                if (! self::validGstin((string) $value)) {
                    $fail('This GSTIN isn\'t valid. Check it against your GST certificate.');
                }
            }],
            'gst_rate' => ['required', 'integer', 'in:'.implode(',', self::RATES)],
            'sac' => ['nullable', 'regex:/^99\d{4}$/'],
            'seller_name' => ['nullable', 'string', 'max:160'],
            'seller_address' => ['nullable', 'string', 'max:300'],
            'seller_email' => ['nullable', 'email:rfc', 'max:190'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'confirm_gst' => ['nullable', 'boolean'], // required only when GST is switched on or off (see the controller)
        ];
    }

    /** GSTIN format and check digit (the 15th character), per the GSTN algorithm. */
    public static function validGstin(string $gstin): bool
    {
        $gstin = strtoupper(trim($gstin));
        if (! preg_match('/^(0[1-9]|[1-3][0-9])[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            return false;
        }
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $product = strpos($chars, $gstin[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return $gstin[14] === $chars[(36 - ($sum % 36)) % 36];
    }

    /** Saves changed fields. Returns [before, after] of what changed. */
    public function save(array $input, User $by): array
    {
        $saved = $this->saved();
        $current = $this->current();
        $before = $after = [];
        foreach (self::FIELDS as $field => [, $type]) {
            $new = match ($type) {
                'bool' => ! empty($input[$field]),
                'rate' => (int) $input[$field],
                'gstin' => filled($input[$field] ?? null) ? strtoupper(trim((string) $input[$field])) : null,
                default => filled($input[$field] ?? null) ? trim((string) $input[$field]) : null,
            };
            $old = $saved[$field] ?? null;
            if ($new === $old) {
                continue;
            }
            if (! array_key_exists($field, $saved)) {
                // Never saved before: an empty box, or the switch/rate left as they already are, isn't a change.
                // Anything typed in is saved, even when it matches the default in use today.
                if ($new === null || (in_array($type, ['bool', 'rate'], true) && $new == $current[$field])) {
                    continue;
                }
            }
            DB::table('platform_settings')->updateOrInsert(['key' => self::PREFIX.$field],
                ['value' => json_encode($new), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
            $before[$field] = $old ?? $current[$field];
            $after[$field] = $new;
        }
        Cache::forget(self::CACHE_KEY);
        $this->apply();

        return [$before, $after];
    }
}
