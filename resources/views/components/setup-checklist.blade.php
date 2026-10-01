@props(['steps'])
{{-- First-run checklist. Hidden once every step is done. --}}
@php $done = count(array_filter($steps, fn ($s) => $s[2])); $total = count($steps); @endphp
@if ($done < $total)
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
            <div>
                <h2 class="font-semibold">Get started</h2>
                <p class="text-sm text-slate-500">{{ $done }} of {{ $total }} done</p>
            </div>
            <div class="h-2 w-40 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $done }}" aria-valuemin="0" aria-valuemax="{{ $total }}">
                <div class="h-full rounded-full bg-emerald-600" style="width: {{ (int) round($done / max(1, $total) * 100) }}%"></div>
            </div>
        </div>
        <ol class="divide-y divide-slate-100">
            @foreach ($steps as $i => [$title, $help, $isDone, $link])
                <li>
                    <a href="{{ $link }}" class="flex items-center gap-4 px-6 py-3.5 hover:bg-slate-50">
                        @if ($isDone)
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-xs font-bold text-white" aria-label="Done">✓</span>
                        @else
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-slate-200 text-xs font-semibold text-slate-500">{{ $i + 1 }}</span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium {{ $isDone ? 'text-slate-400 line-through' : 'text-slate-900' }}">{{ $title }}</span>
                            @unless ($isDone)<span class="block text-xs text-slate-500">{{ $help }}</span>@endunless
                        </span>
                        @unless ($isDone)<x-icon name="right" class="size-4 text-slate-400" />@endunless
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endif
