@extends('layouts.app')

@section('title', 'Approval rules')

@section('content')
    @php
        $field = 'block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $inr = fn ($v) => \App\Support\Money::inr($v, 0);
    @endphp
    <x-page-header title="Approval rules" subtitle="Who must approve an award before its purchase order goes out. Levels are approved one after another, in this order." />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @forelse ($rules as $i => $rule)
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="flex gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">{{ $i + 1 }}</span>
                            <div>
                                <h2 class="font-semibold">{{ $rule->name }}</h2>
                                <p class="text-sm text-slate-600">{{ $rule->describe() }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">Approved by {{ $rule->approver ? $rule->approver->name : 'any approver or admin' }}</p>
                            </div>
                        </div>
                        @if ($isAdmin)
                            <div class="flex items-center gap-1">
                                @foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow)
                                    @if (($dir === 'up' && $i > 0) || ($dir === 'down' && $i < $rules->count() - 1))
                                        <form method="POST" action="{{ route('buyer.approval-rules.update', $rule->id) }}">
                                            @csrf @method('PUT')
                                            @foreach (['name', 'min_amount', 'approver_user_id'] as $f)<input type="hidden" name="rule{{ $rule->id }}_{{ $f }}" value="{{ $rule->$f }}">@endforeach
                                            @foreach (['when_not_l1', 'when_single_quote', 'when_new_supplier'] as $f)@if ($rule->$f)<input type="hidden" name="rule{{ $rule->id }}_{{ $f }}" value="1">@endif @endforeach
                                            <button name="move" value="{{ $dir }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-sm text-slate-600 hover:bg-slate-50" title="Move {{ $dir }}" aria-label="Move {{ $dir }}">{{ $arrow }}</button>
                                        </form>
                                    @endif
                                @endforeach
                                <form method="POST" action="{{ route('buyer.approval-rules.destroy', $rule->id) }}" data-confirm="Remove the level &quot;{{ $rule->name }}&quot;?">
                                    @csrf @method('DELETE')
                                    <button class="rounded-lg border border-slate-200 px-2.5 py-1 text-sm text-red-700 hover:bg-red-50">Remove</button>
                                </form>
                            </div>
                        @endif
                    </div>
                    @if ($isAdmin)
                        <details class="border-t border-slate-100">
                            <summary class="cursor-pointer px-5 py-2.5 text-sm font-medium text-emerald-700 hover:bg-slate-50">Edit</summary>
                            @include('buyer._approval-rule-form', ['rule' => $rule, 'prefix' => 'rule'.$rule->id.'_', 'action' => route('buyer.approval-rules.update', $rule->id), 'method' => 'PUT', 'button' => 'Save level'])
                        </details>
                    @endif
                </section>
            @empty
                <section class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
                    <p class="font-medium text-slate-900">No approval rules yet</p>
                    <p class="mt-1">
                        Right now an award needs one approval
                        {{ (float) $legacyLimit > 0 ? 'from '.$inr($legacyLimit).' (before GST)' : '' }} when your team has an Approver; otherwise the PO goes out straight away.
                        Add levels to set this up your way, for example: Purchase manager from ₹1 lakh, Plant head from ₹5 lakh, Director from ₹25 lakh or when L1 isn't chosen.
                    </p>
                </section>
            @endforelse
        </div>

        <aside class="space-y-6">
            @if ($isAdmin)
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 px-5 py-4 font-semibold">Add a level</h2>
                    @include('buyer._approval-rule-form', ['rule' => null, 'prefix' => '', 'action' => route('buyer.approval-rules.store'), 'method' => 'POST', 'button' => 'Add level'])
                </section>
            @endif
            <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600 shadow-sm">
                <h2 class="font-semibold text-slate-900">How it works</h2>
                <ul class="mt-2 list-disc space-y-1.5 pl-4">
                    <li>Each level that applies must approve, in order. The first "no" rejects the award.</li>
                    <li>Nobody approves their own award, and one person approves only one level of it.</li>
                    <li>If the named approver leaves, any approver or admin can approve that level.</li>
                    <li>Changes apply to new awards. Awards already waiting keep their levels.</li>
                    @if ($approvers->isEmpty())<li class="font-medium text-amber-800">Add an Approver in Team: without one, awards go out without approval.</li>@endif
                </ul>
            </section>
        </aside>
    </div>
@endsection
