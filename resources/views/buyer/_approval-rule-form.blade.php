@php $field = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20'; @endphp
<form method="POST" action="{{ $action }}" class="space-y-4 px-5 py-4">
    @csrf @if ($method === 'PUT') @method('PUT') @endif
    <div>
        <label for="{{ $prefix }}name" class="block text-sm font-medium">Level name</label>
        <input id="{{ $prefix }}name" name="{{ $prefix }}name" value="{{ old($prefix.'name', $rule?->name) }}" required maxlength="80" class="{{ $field }}" placeholder="Plant head">
    </div>
    <div>
        <label for="{{ $prefix }}min_amount" class="block text-sm font-medium">For awards from (₹, before GST)</label>
        <input id="{{ $prefix }}min_amount" name="{{ $prefix }}min_amount" inputmode="decimal" value="{{ old($prefix.'min_amount', $rule?->min_amount !== null ? rtrim(rtrim((string) $rule->min_amount, '0'), '.') : '') }}" class="{{ $field }}" placeholder="500000">
        <p class="mt-1 text-xs text-slate-500">0 for every award. Leave empty to use only the conditions below.</p>
    </div>
    <fieldset>
        <legend class="text-sm font-medium">Also when</legend>
        <div class="mt-2 space-y-2 text-sm">
            @foreach (['when_not_l1' => 'The award doesn’t go to L1', 'when_single_quote' => 'Only one quote was received', 'when_new_supplier' => 'It’s the first order with the supplier'] as $f => $label)
                <label class="flex items-center gap-2"><input type="checkbox" name="{{ $prefix }}{{ $f }}" value="1" @checked(old($prefix.$f, $rule?->$f)) class="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"> {{ $label }}</label>
            @endforeach
        </div>
    </fieldset>
    <div>
        <label for="{{ $prefix }}approver_user_id" class="block text-sm font-medium">Approved by</label>
        <select id="{{ $prefix }}approver_user_id" name="{{ $prefix }}approver_user_id" class="{{ $field }}">
            <option value="">Any approver or admin</option>
            @foreach ($approvers as $u)<option value="{{ $u->id }}" @selected((int) old($prefix.'approver_user_id', $rule?->approver_user_id) === $u->id)>{{ $u->name }}</option>@endforeach
        </select>
    </div>
    <button class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ $button }}</button>
</form>
