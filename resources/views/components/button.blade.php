<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex w-full items-center justify-center rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-600/40 disabled:opacity-60']) }}>
    {{ $slot }}
</button>
