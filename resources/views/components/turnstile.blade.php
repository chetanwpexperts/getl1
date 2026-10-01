{{-- Cloudflare Turnstile bot check. Renders nothing until the keys are set in .env. --}}
@if (\App\Services\Turnstile::enabled())
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="light" data-size="flexible"></div>
    @error('turnstile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
@endif
