@php $cls = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20'; @endphp
<div class="rounded-lg border border-slate-200 p-3" data-item-row>
    <div class="grid gap-3 sm:grid-cols-12">
        <div class="sm:col-span-5">
            <label class="block text-xs font-medium text-slate-600">Item</label>
            <input name="items[{{ $i }}][name]" value="{{ $row['name'] ?? '' }}" required maxlength="150" class="{{ $cls }}">
            @error("items.$i.name") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">Unit</label>
            <select name="items[{{ $i }}][unit]" class="{{ $cls }}">
                @foreach ($units as $u)<option value="{{ $u }}" @selected(($row['unit'] ?? 'pcs') === $u)>{{ $u }}</option>@endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label class="block text-xs font-medium text-slate-600">Agreed rate per unit (₹, before GST)</label>
            <input name="items[{{ $i }}][rate]" value="{{ $row['rate'] ?? '' }}" required inputmode="decimal" class="{{ $cls }}">
            @error("items.$i.rate") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600">GST %</label>
            <select name="items[{{ $i }}][gst_rate]" class="{{ $cls }}">
                <option value="">—</option>
                @foreach (\App\Services\QuoteService::GST_RATES as $g)<option value="{{ $g }}" @selected(isset($row['gst_rate']) && (string) (float) $row['gst_rate'] === $g)>{{ $g }}%</option>@endforeach
            </select>
        </div>
        <div class="sm:col-span-10">
            <label class="block text-xs font-medium text-slate-600">Specification</label>
            <input name="items[{{ $i }}][spec]" value="{{ $row['spec'] ?? '' }}" maxlength="1000" class="{{ $cls }}">
        </div>
        <div class="flex items-end sm:col-span-2">
            <button type="button" data-remove-item class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700">Remove</button>
        </div>
    </div>
</div>
