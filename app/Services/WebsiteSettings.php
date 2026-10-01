<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Website content and branding, edited in the admin console (Website page).
 *
 * Saved values are laid over config('site.*') when the app boots (see AppServiceProvider),
 * so every page, email and policy reads them the same way. Empty means "use the default".
 */
class WebsiteSettings
{
    private const CACHE_KEY = 'website_settings:v1';
    private const PREFIX = 'website.';

    /** section => [field => [type, label, help, max length]] ; type: text|textarea|email|url|tel|image|bool|date|ga4|token */
    public const SECTIONS = [
        'Brand' => [
            'name' => ['text', 'Site name', 'Shown in the browser tab and emails.', 60],
            'tagline' => ['text', 'Tagline', 'One line under the logo in the footer.', 160],
            'logo' => ['image', 'Logo', 'PNG or JPG, ideally wide and transparent (about 320 × 80). Replaces the text logo everywhere.', 0],
            'favicon' => ['image', 'Favicon', 'Square PNG, at least 64 × 64.', 0],
            'og_image' => ['image', 'Share image', 'Shown when a link is shared on WhatsApp, LinkedIn and others. 1200 × 630.', 0],
        ],
        'Home page' => [
            'hero_headline' => ['text', 'Headline', 'Leave empty for "Make your suppliers compete. Buy at L1."', 120],
            'hero_subtext' => ['textarea', 'Intro text', 'The paragraph under the headline.', 400],
            'home_title' => ['text', 'Browser title (SEO)', 'Up to about 60 characters.', 80],
            'home_description' => ['textarea', 'Search description (SEO)', 'Up to about 155 characters, shown by Google.', 200],
        ],
        'Announcement bar' => [
            'announcement_on' => ['bool', 'Show the announcement bar', 'A thin bar above the website header.', 0],
            'announcement_text' => ['text', 'Text', 'e.g. "Early access is open for October."', 140],
            'announcement_link' => ['url', 'Link (optional)', 'Where the bar links to.', 255],
        ],
        'Contact and legal' => [
            'legal_name' => ['text', 'Registered business name', 'Exactly as registered. Shown on policies, invoices and the footer; Razorpay checks it.', 160],
            'address' => ['textarea', 'Registered address', '', 300],
            'gstin' => ['text', 'GSTIN (optional)', 'Shown in the footer when set.', 15],
            'email' => ['email', 'Support email', 'Shown on the website, policies and in emails.', 190],
            'phone' => ['tel', 'Phone', '', 20],
            'whatsapp' => ['tel', 'WhatsApp number', 'With country code, e.g. 919876543210. Adds a WhatsApp button on the contact page.', 15],
            'grievance_officer' => ['text', 'Grievance Officer', 'Required by Indian IT rules on the policies.', 120],
            'jurisdiction' => ['text', 'Courts (jurisdiction)', 'e.g. Panchkula, Haryana', 120],
            'policies_updated' => ['date', 'Policies last updated', 'Shown at the top of each policy.', 0],
            'leads_to' => ['email', 'Send new leads to', 'Who gets the email when someone fills the contact form.', 190],
        ],
        'Social links' => [
            'social_linkedin' => ['url', 'LinkedIn', '', 255],
            'social_instagram' => ['url', 'Instagram', '', 255],
            'social_facebook' => ['url', 'Facebook', '', 255],
            'social_x' => ['url', 'X (Twitter)', '', 255],
            'social_youtube' => ['url', 'YouTube', '', 255],
        ],
        'Footer' => [
            'footer_note' => ['text', 'Footer note', 'Small line at the very bottom, e.g. "Made in India."', 160],
        ],
        'Analytics and search' => [
            'ga4_id' => ['ga4', 'Google Analytics ID', 'Measurement ID like G-ABC123XYZ. Leave empty for no analytics.', 20],
            'google_verification' => ['token', 'Google Search Console code', 'Only the content="…" value of the verification tag.', 100],
        ],
    ];

    public static function fields(): array
    {
        return array_merge(...array_values(self::SECTIONS));
    }

    /** All saved values (only keys that were saved). */
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
            return []; // database not ready (fresh install, migrations): defaults, and nothing cached
        }
        Cache::put(self::CACHE_KEY, $values, 300);

        return $values;
    }

    /**
     * Lays saved values over config('site.*'). Runs at boot and on every request. The value each key
     * had before the first overlay is remembered (config site._defaults), so clearing a setting
     * brings its default back.
     */
    public function apply(): void
    {
        $defaults = (array) config('site._defaults', []);
        foreach ($this->saved() as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                $defaults[$key] = config('site.'.$key);
            }
            config(['site.'.$key => $value === null || $value === '' ? $defaults[$key] : $value]);
        }
        config(['site._defaults' => $defaults]);
        if (config('site.name')) {
            config(['app.name' => config('site.name')]);
        }
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (self::fields() as $key => [$type, , , $max]) {
            $rules[$key] = match ($type) {
                'image' => ['nullable', 'file', 'max:2048'],
                'bool' => ['nullable', 'boolean'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'email' => ['nullable', 'email:rfc', 'max:'.$max],
                'url' => ['nullable', 'url:https,http', 'max:'.$max],
                'tel' => ['nullable', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
                'ga4' => ['nullable', 'regex:/^G-[A-Z0-9]{4,15}$/'],
                'token' => ['nullable', 'regex:/^[A-Za-z0-9_-]{10,100}$/'],
                default => ['nullable', 'string', 'max:'.$max],
            };
            $rules['remove_'.$key] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * Saves the submitted form. Images are content-checked and stored under storage/app/public/site.
     *
     * @return array{0: array, 1: array} before, after (changed keys only; images as paths)
     */
    public function save(array $input, array $files, User $by, FileGuard $guard): array
    {
        $saved = $this->saved();
        $before = $after = [];
        foreach (self::fields() as $key => [$type]) {
            $old = $saved[$key] ?? null;
            if ($type === 'image') {
                $new = $old;
                if (! empty($input['remove_'.$key])) {
                    $new = null;
                }
                if (($files[$key] ?? null) instanceof UploadedFile) {
                    $ext = $guard->check($files[$key], [FileGuard::PNG, FileGuard::JPG], 2048, 'website_'.$key, $key);
                    $new = $files[$key]->storeAs('site', $key.'-'.Str::random(12).'.'.$ext, 'public');
                }
                if ($new !== $old && $old) {
                    Storage::disk('public')->delete($old);
                }
            } elseif ($type === 'bool') {
                $new = ! empty($input[$key]);
            } else {
                $new = isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
                if ($type === 'tel' && $new !== null) {
                    $new = preg_replace('/[^\d+]/', '', $new);
                }
            }
            if ($new === $old) {
                continue;
            }
            DB::table('platform_settings')->updateOrInsert(['key' => self::PREFIX.$key],
                ['value' => json_encode($new), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
            $before[$key] = $old;
            $after[$key] = $new;
        }
        Cache::forget(self::CACHE_KEY);

        return [$before, $after];
    }

    /** Public URL of an uploaded image setting, or null. */
    public static function url(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }
}
