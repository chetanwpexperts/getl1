@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])

<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type === 'password' ? '' : old($name, $value) }}"
           {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 shadow-sm focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none']) }}>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
