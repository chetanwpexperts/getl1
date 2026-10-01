@extends('layouts.admin')

@section('title', 'Platform rules')

@section('content')
    <h1 class="text-2xl font-semibold">Platform rules</h1>
    <p class="mt-1 text-sm text-slate-600">Defaults buyers start from when they schedule an auction, and the limits they can't go past. Auctions already scheduled keep their own rules. Every change is logged.</p>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-6 max-w-3xl space-y-4">
        @csrf
        <section class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
            @foreach ($fields as $key => [$default, $rules, $label, $help])
                @php $name = str_replace('.', '__', $key); $val = old($name, $values[$key]); @endphp
                <div class="grid gap-2 px-5 py-4 sm:grid-cols-[1fr_12rem] sm:items-center">
                    <div>
                        <label for="{{ $name }}" class="font-medium">{{ $label }}</label>
                        @if ($help)<p class="text-xs text-slate-500">{{ $help }}</p>@endif
                        <p class="text-xs text-slate-400">Default {{ $default ?? 'none' }}</p>
                        @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @if (in_array('in:0,60,120,180,300', $rules, true) || in_array('in:60,120,180,300', $rules, true))
                        <select id="{{ $name }}" name="{{ $name }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            @foreach (array_map('intval', explode(',', \Illuminate\Support\Str::after(collect($rules)->first(fn ($r) => str_starts_with($r, 'in:')), 'in:'))) as $opt)
                                <option value="{{ $opt }}" @selected((int) $val === $opt)>{{ $opt === 0 ? 'Off' : ($opt / 60).' min' }}</option>
                            @endforeach
                        </select>
                    @elseif (in_array('email:rfc', $rules, true))
                        <input id="{{ $name }}" name="{{ $name }}" type="email" value="{{ $val }}" maxlength="190" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @else
                        <input id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" inputmode="decimal" required class="rounded-lg border border-slate-300 px-3 py-2 text-right text-sm tabular-nums">
                    @endif
                </div>
            @endforeach
        </section>
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1">
                <label for="reason" class="text-sm font-medium">Reason for the change</label>
                <input id="reason" name="reason" required minlength="5" maxlength="200" value="{{ old('reason') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <button class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white">Save rules</button>
        </div>
    </form>
@endsection
