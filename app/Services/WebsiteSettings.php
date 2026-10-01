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
            'credit_on' => ['bool', 'Show the "Developed by" credit', 'Small credit line in the website, app, admin and email footers.', 0],
            'credit_text' => ['text', 'Credit wording', 'e.g. "Developed by" or "Designed and developed by"', 60],
            'credit_name' => ['text', 'Developer name', '', 80],
            'credit_url' => ['url', 'Developer website', '', 255],
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
                    if ($key === 'logo' && function_exists('imagepng')) {
                        $ext = 'png'; // keeps the transparent background
                    }
                    $new = 'site/'.$key.'-'.Str::random(12).'.'.$ext;
                    Storage::disk('public')->put($new, self::shrink((string) file_get_contents($files[$key]->getRealPath()), $ext, self::MAX_SIZE[$key] ?? [1200, 1200], trim: $key === 'logo'));
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

    /** Largest width × height kept for each image; bigger uploads are scaled down so pages stay fast. */
    private const MAX_SIZE = ['logo' => [800, 200], 'favicon' => [256, 256], 'og_image' => [1200, 630]];

    /**
     * Scales an image down to fit the box (optionally trimming empty borders first) and re-encodes it (which also drops anything hidden in the file).
     * Without the GD extension, or if the image can't be read, the original is kept.
     */
    public static function shrink(string $bytes, string $ext, array $box, bool $trim = false): string
    {
        if (! function_exists('imagecreatefromstring') || ! ($src = @imagecreatefromstring($bytes))) {
            return $bytes;
        }
        if ($trim) {
            // Cut empty (transparent or plain-colour) borders so the logo fills the space it's given.
            imagesavealpha($src, true);
            $cropped = @imagecropauto($src, IMG_CROP_SIDES);
            if ($cropped && imagesx($cropped) > 8 && imagesy($cropped) > 8) {
                $src = $cropped;
            }
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, $box[0] / $w, $box[1] / $h);
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $dst = imagecreatetruecolor($nw, $nh);
        if ($ext === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if ($trim && $ext === 'png') {
            self::clearWhiteBackground($dst);
            $dst = self::cropToVisible($dst);
        }
        ob_start();
        $ok = $ext === 'png' ? imagepng($dst, null, 9) : imagejpeg($dst, null, 85);
        $out = (string) ob_get_clean();

        return $ok && $out !== '' ? $out : $bytes;
    }

    /**
     * A logo saved on a plain white background gets that background made transparent, so it sits
     * cleanly on any header colour. Only the white area joined to the edges is cleared; the logo itself
     * (including any white inside it) stays solid, and its soft edges are blended so they don't look jagged.
     * Nothing happens unless all four corners are white.
     */
    private static function clearWhiteBackground(\GdImage $img): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $rgba = fn ($x, $y) => imagecolorsforindex($img, imagecolorat($img, $x, $y));
        $white = fn ($c) => $c['alpha'] < 20 && min($c['red'], $c['green'], $c['blue']) >= 236;
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            if (! $white($rgba($x, $y))) {
                return;
            }
        }

        // Flood fill from the edges through white pixels: that's the background.
        $bg = [];
        $queue = new \SplQueue;
        for ($x = 0; $x < $w; $x++) { $queue->enqueue([$x, 0]); $queue->enqueue([$x, $h - 1]); }
        for ($y = 0; $y < $h; $y++) { $queue->enqueue([0, $y]); $queue->enqueue([$w - 1, $y]); }
        while (! $queue->isEmpty()) {
            [$x, $y] = $queue->dequeue();
            $k = $y * $w + $x;
            if (isset($bg[$k]) || ! $white($rgba($x, $y))) {
                continue;
            }
            $bg[$k] = true;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                [$nx, $ny] = [$x + $dx, $y + $dy];
                if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && ! isset($bg[$ny * $w + $nx])) {
                    $queue->enqueue([$nx, $ny]);
                }
            }
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 255, 255, 255, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $k = $y * $w + $x;
                if (isset($bg[$k])) {
                    imagesetpixel($img, $x, $y, $clear);
                    continue;
                }
                // Soft edge pixels next to the background: un-mix them from white so the edge stays smooth.
                $edge = ($x > 0 && isset($bg[$k - 1])) || ($x < $w - 1 && isset($bg[$k + 1])) || ($y > 0 && isset($bg[$k - $w])) || ($y < $h - 1 && isset($bg[$k + $w]));
                $c = $rgba($x, $y);
                if (! $edge || min($c['red'], $c['green'], $c['blue']) < 150) {
                    continue;
                }
                $a = max(255 - $c['red'], 255 - $c['green'], 255 - $c['blue']) / 255;
                $a = max(0.05, min(1, $a / 0.6));
                $un = fn ($v) => (int) max(0, min(255, round(255 - (255 - $v) / $a)));
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $un($c['red']), $un($c['green']), $un($c['blue']), (int) round(127 * (1 - $a))));
            }
        }
    }

    /** Cuts away fully see-through margins so the logo fills the height it's shown at. */
    private static function cropToVisible(\GdImage $img): \GdImage
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        [$x0, $y0, $x1, $y1] = [$w, $h, -1, -1];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((imagecolorat($img, $x, $y) >> 24) < 120) { // visible pixel
                    $x0 = min($x0, $x); $x1 = max($x1, $x); $y0 = min($y0, $y); $y1 = max($y1, $y);
                }
            }
        }
        if ($x1 < 0 || ($x0 === 0 && $y0 === 0 && $x1 === $w - 1 && $y1 === $h - 1)) {
            return $img;
        }
        $out = imagecreatetruecolor($x1 - $x0 + 1, $y1 - $y0 + 1);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopy($out, $img, 0, 0, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1);

        return $out;
    }

    /** Public URL of an uploaded image setting, or null. */
    public static function url(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }
}
