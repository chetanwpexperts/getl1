{{-- Header shared by the policy pages. --}}
<header class="border-b border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $heading }}</h1>
        <p class="mt-2 text-sm text-slate-500">Last updated {{ config('site.policies_updated') }}</p>
    </div>
</header>
