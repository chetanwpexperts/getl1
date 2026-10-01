@php
    $cls = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
    $doubt = $doubt ?? [];
    // AI marked these as hard to read: yellow until the buyer touches the field.
    $mark = fn (string $f) => in_array($f, $doubt, true) ? ' ai-doubt' : '';
    $note = fn (string $f) => in_array($f, $doubt, true) ? '<p class="mt-1 text-xs font-medium text-amber-700" data-ai-doubt-note>Check this</p>' : '';
@endphp
<div class="rounded-lg border border-slate-200 p-3" data-item-row>
    <div class="grid gap-3 sm:grid-cols-12">
        <div class="sm:col-span-4">
            <label class="block text-xs font-medium text-slate-600">Item</label>
            <input name="items[{{ $i }}][name]" value="{{ $row['name'] ?? '' }}" required maxlength="150" class="{{ $cls }}{{ $mark('name') }}" placeholder="Corrugated box">
            {!! $note('name') !!}
            @error("items.$i.name") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">Quantity</label>
            <input name="items[{{ $i }}][qty]" value="{{ $row['qty'] ?? '' }}" required inputmode="decimal" class="{{ $cls }}{{ $mark('qty') }}" placeholder="10000">
            {!! $note('qty') !!}
            @error("items.$i.qty") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">Unit</label>
            <select name="items[{{ $i }}][unit]" class="{{ $cls }}{{ $mark('unit') }}">
                @foreach (\App\Services\RfqService::UNITS as $u)
                    <option value="{{ $u }}" @selected(($row['unit'] ?? 'pcs') === $u)>{{ $u }}</option>
                @endforeach
            </select>
            {!! $note('unit') !!}
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">Needed by</label>
            <input type="date" name="items[{{ $i }}][delivery_date]" value="{{ $row['delivery_date'] ?? '' }}" class="{{ $cls }}{{ $mark('delivery_date') }}">
            {!! $note('delivery_date') !!}
            @error("items.$i.delivery_date") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">Last price (₹, private)</label>
            <input name="items[{{ $i }}][last_purchase_price]" value="{{ $row['last_purchase_price'] ?? '' }}" inputmode="decimal" class="{{ $cls }}{{ $mark('last_purchase_price') }}">
            {!! $note('last_purchase_price') !!}
            @error("items.$i.last_purchase_price") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-10">
            <label class="block text-xs font-medium text-slate-600">Specification</label>
            <input name="items[{{ $i }}][spec]" value="{{ $row['spec'] ?? '' }}" maxlength="1000" class="{{ $cls }}{{ $mark('spec') }}" placeholder="5-ply, 18×12×12 inch, brown kraft">
            {!! $note('spec') !!}
        </div>
        <div class="flex items-end sm:col-span-2">
            <button type="button" data-remove-item class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700">Remove</button>
        </div>
    </div>
</div>
