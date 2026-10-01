@props(['variant' => 'button'])
{{--
    "Install app". Hidden when already running as the installed app (see resources/js/pwa.js).
    Variants: card (login page), button (app header), dark (admin sidebar).
    Where the browser can install directly, one click installs; otherwise the steps for that browser are shown.
--}}
@php
    $icon = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4" aria-hidden="true"><path d="M10 3v9m0 0-3.5-3.5M10 12l3.5-3.5"/><path d="M4 13.5v1.75C4 16.22 4.78 17 5.75 17h8.5c.97 0 1.75-.78 1.75-1.75V13.5"/></svg>';
    $tip = '<span data-install-steps class="block"></span>';
@endphp

@if ($variant === 'card')
    <div data-install-app hidden class="w-full rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4 shadow-sm">
        <div class="flex items-center gap-4">
            <img src="{{ asset('icons/icon-192.png') }}" alt="" class="size-12 shrink-0 rounded-xl shadow-sm ring-1 ring-emerald-900/10">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900">Get the {{ config('site.name') }} app</p>
                <p class="mt-0.5 text-xs leading-relaxed text-slate-600">Open it in one tap from your phone or desktop. Free, no app store needed.</p>
            </div>
            <button type="button" data-install-button
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                {!! $icon !!} Install
            </button>
        </div>
        <div data-install-tip hidden role="note" class="mt-3 rounded-lg border border-emerald-100 bg-white px-3 py-2.5 text-xs leading-relaxed text-slate-700">{!! $tip !!}</div>
    </div>
@else
    <span data-install-app hidden class="relative inline-flex">
        <button type="button" data-install-button aria-label="Install app" title="Install app"
                class="{{ $variant === 'dark'
                    ? 'inline-flex items-center gap-1.5 rounded-md border border-slate-700 px-2 py-1 text-slate-300 transition hover:border-slate-500 hover:text-white'
                    : 'inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100' }}">
            {!! $icon !!} <span class="{{ $variant === 'dark' ? '' : 'hidden sm:inline' }}">Install app</span>
        </button>
        <span data-install-tip hidden role="note"
              class="absolute z-40 w-72 rounded-xl border border-slate-200 bg-white p-3.5 text-left text-xs leading-relaxed text-slate-700 shadow-xl {{ $variant === 'dark' ? 'bottom-full left-0 mb-2' : 'right-0 top-full mt-2 max-w-[calc(100vw-2rem)]' }}">
            <span class="mb-1.5 flex items-center gap-2 font-semibold text-slate-900">
                <img src="{{ asset('icons/icon-192.png') }}" alt="" class="size-6 rounded-md"> Install {{ config('site.name') }}
            </span>
            {!! $tip !!}
        </span>
    </span>
@endif
