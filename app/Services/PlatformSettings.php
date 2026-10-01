<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide rules, edited by GetL1 staff in the admin console. Every value has a safe
 * default, so the platform works the same before anyone saves anything.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings:v1';

    /** key => [default, validation rules, label, help] */
    public const FIELDS = [
        'auction.default_duration_min' => [30, ['integer', 'min:5', 'max:240'], 'Default auction length (minutes)', 'Pre-selected on the schedule form.'],
        'auction.max_duration_min' => [240, ['integer', 'min:15', 'max:480'], 'Longest auction allowed (minutes)', ''],
        'auction.default_extend_window_sec' => [120, ['integer', 'in:0,60,120,180,300'], 'Default late-bid window (seconds)', 'A bid in this last window extends the auction. 0 turns extensions off by default.'],
        'auction.default_extend_by_sec' => [120, ['integer', 'in:60,120,180,300'], 'Default extension (seconds)', ''],
        'auction.default_max_extensions' => [10, ['integer', 'min:0', 'max:50'], 'Default maximum extensions', ''],
        'auction.max_extensions_limit' => [30, ['integer', 'min:1', 'max:50'], 'Most extensions a buyer may allow', 'Keeps every auction finite.'],
        'auction.default_min_decrement_pct' => [0.5, ['numeric', 'min:0.1', 'max:10'], 'Default minimum bid step (%)', 'How much a supplier must go below their own last price.'],
        'auction.default_max_decrement_pct' => [10, ['numeric', 'min:1', 'max:50'], 'Default typo guard (%)', 'Bids more than this far below the current L1 are refused as a likely typo.'],
        'alerts.email' => [null, ['nullable', 'email:rfc', 'max:190'], 'System alert email', 'Where health alerts go. Empty uses the leads address.'],
    ];

    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => rescue(
            fn () => DB::table('platform_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all(), [], false));

        $out = [];
        foreach (self::FIELDS as $key => [$default]) {
            $out[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $key => [, $r]) {
            $rules[str_replace('.', '__', $key)] = array_merge(in_array('nullable', $r, true) ? [] : ['required'], $r);
        }

        return $rules;
    }

    /** Saves changed values. Returns [before, after] of the keys that changed. */
    public function save(array $input, User $by): array
    {
        $current = $this->all();
        $before = $after = [];
        foreach (self::FIELDS as $key => [$default, $r]) {
            $field = str_replace('.', '__', $key);
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (in_array('integer', $r, true) && $value !== null) {
                $value = (int) $value;
            } elseif (in_array('numeric', $r, true) && $value !== null) {
                $value = round((float) $value, 2);
            } elseif ($value === '') {
                $value = null;
            }
            if ($value === $current[$key]) {
                continue;
            }
            DB::table('platform_settings')->updateOrInsert(['key' => $key],
                ['value' => json_encode($value), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
            $before[$key] = $current[$key];
            $after[$key] = $value;
        }
        Cache::forget(self::CACHE_KEY);

        return [$before, $after];
    }
}
