@props(['class' => '', 'up' => false])
{{-- Shown only when the device can install the app and it isn't installed yet (see resources/js/pwa.js). --}}
<span data-install-app hidden class="relative">
    <button type="button" data-install-button class="{{ $class }}">Install app</button>
    <span data-install-tip hidden role="note"
          class="absolute z-40 w-64 {{ $up ? 'bottom-full left-0 mb-2' : 'right-0 top-full mt-2' }} rounded-lg border border-slate-200 bg-white p-3 text-left text-xs leading-relaxed text-slate-700 shadow-lg">
        On iPhone or iPad: tap <strong>Share</strong> <span aria-hidden="true">⎙</span> in Safari, then <strong>Add to Home Screen</strong>.
    </span>
</span>
