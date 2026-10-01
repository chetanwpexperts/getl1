@extends('layouts.admin')

@section('title', 'Website')

@section('content')
    @php
        $input = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $val = fn ($k) => old($k, $saved[$k] ?? null);
        $default = fn ($k) => config('site.'.$k);
    @endphp
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Website</h1>
            <p class="mt-1 text-sm text-slate-600">Branding, home page text, contact and legal details, social links and analytics. Empty fields use the default shown in grey. Every change is logged.</p>
        </div>
        <a href="{{ route('home') }}" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50">View website ↗</a>
    </div>

    <form method="POST" action="{{ route('admin.website.update') }}" enctype="multipart/form-data" class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_16rem]">
        @csrf
        <div class="space-y-6">
            @foreach ($sections as $section => $fields)
                <section id="{{ \Illuminate\Support\Str::slug($section) }}" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-100 px-5 py-3.5 font-semibold">{{ $section }}</h2>
                    <div class="divide-y divide-slate-100">
                        @foreach ($fields as $key => [$type, $label, $help, $max])
                            <div class="grid gap-2 px-5 py-4 md:grid-cols-[14rem_minmax(0,1fr)]">
                                <div>
                                    <label for="{{ $key }}" class="text-sm font-medium">{{ $label }}</label>
                                    @if ($help)<p class="mt-0.5 text-xs text-slate-500">{{ $help }}</p>@endif
                                </div>
                                <div class="min-w-0">
                                    @switch($type)
                                        @case('image')
                                            <div class="flex flex-wrap items-center gap-4">
                                                @if (! empty($saved[$key]))
                                                    <img src="{{ \App\Services\WebsiteSettings::url($saved[$key]) }}" alt="" class="max-h-16 max-w-48 rounded border border-slate-200 bg-[repeating-conic-gradient(#f1f5f9_0_25%,#fff_0_50%)] bg-[length:12px_12px] object-contain p-1">
                                                    <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remove_{{ $key }}" value="1" class="rounded border-slate-300"> Remove</label>
                                                @else
                                                    <span class="text-xs text-slate-500">Using the default.</span>
                                                @endif
                                            </div>
                                            <input id="{{ $key }}" type="file" name="{{ $key }}" accept="image/png,image/jpeg"
                                                   class="mt-2 block text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
                                            @break
                                        @case('bool')
                                            <label class="inline-flex items-center gap-2 text-sm"><input id="{{ $key }}" type="checkbox" name="{{ $key }}" value="1" @checked($val($key)) class="size-4 rounded border-slate-300 accent-emerald-700"> On</label>
                                            @break
                                        @case('textarea')
                                            <textarea id="{{ $key }}" name="{{ $key }}" rows="3" maxlength="{{ $max }}" placeholder="{{ $default($key) }}" class="{{ $input }}">{{ $val($key) }}</textarea>
                                            @break
                                        @case('date')
                                            <input id="{{ $key }}" type="date" name="{{ $key }}" value="{{ $val($key) }}" class="{{ $input }} max-w-48">
                                            @if (! $val($key))<p class="mt-1 text-xs text-slate-400">Default {{ $default($key) }}</p>@endif
                                            @break
                                        @default
                                            <input id="{{ $key }}" name="{{ $key }}" value="{{ $val($key) }}" @if ($max) maxlength="{{ $max }}" @endif
                                                   type="{{ ['email' => 'email', 'url' => 'url', 'tel' => 'tel'][$type] ?? 'text' }}"
                                                   placeholder="{{ is_string($default($key)) ? \Illuminate\Support\Str::limit($default($key), 80) : '' }}" class="{{ $input }}">
                                    @endswitch
                                    @error($key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <aside class="space-y-3 xl:sticky xl:top-6 xl:self-start">
            <nav class="rounded-2xl border border-slate-200 bg-white p-3 text-sm shadow-sm" aria-label="Sections">
                @foreach (array_keys($sections) as $section)
                    <a href="#{{ \Illuminate\Support\Str::slug($section) }}" class="block rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-50 hover:text-slate-900">{{ $section }}</a>
                @endforeach
            </nav>
            <button class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save changes</button>
            <p class="text-xs text-slate-500">Images: PNG or JPG up to 2 MB, checked by content. Changes show on the website straight away.</p>
        </aside>
    </form>
@endsection
