<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GetL1: Make your suppliers compete. Buy at L1.</title>
    <meta name="description" content="Reverse auction software for Indian SMEs. Invite suppliers, run a live 30-minute auction, and buy at the lowest price. Suppliers bid from WhatsApp.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5">
        <span class="text-2xl font-bold tracking-tight">Get<span class="text-emerald-700">L1</span></span>
        <nav class="flex items-center gap-4 text-sm font-medium">
            @auth
                <a href="{{ route('dashboard') }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-white hover:bg-emerald-800">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900">Log in</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-white hover:bg-emerald-800">Start free trial</a>
            @endauth
        </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4">
        <section class="py-16 sm:py-24">
            <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl">
                Stop haggling on WhatsApp.<br>Let your suppliers bid the price down.
            </h1>
            <p class="mt-6 max-w-2xl text-lg text-slate-600">
                Post what you need, invite your suppliers, and run a live 30-minute reverse auction.
                Suppliers see only their rank and bid from their phone. You buy at L1.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="rounded-lg bg-emerald-700 px-5 py-3 font-semibold text-white hover:bg-emerald-800">Start 30-day free trial</a>
                <a href="{{ route('register', ['as' => 'supplier']) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold hover:bg-slate-50">I'm a supplier</a>
            </div>
        </section>

        <section class="grid gap-6 border-t border-slate-200 py-16 sm:grid-cols-3">
            <div>
                <h2 class="font-semibold">1. Post your requirement</h2>
                <p class="mt-2 text-sm text-slate-600">Items, quantity, delivery date. Upload your Excel sheet and it fills in for you.</p>
            </div>
            <div>
                <h2 class="font-semibold">2. Suppliers bid live</h2>
                <p class="mt-2 text-sm text-slate-600">Names stay hidden. Each supplier sees only their rank, so they keep going lower.</p>
            </div>
            <div>
                <h2 class="font-semibold">3. Award and send the PO</h2>
                <p class="mt-2 text-sm text-slate-600">Compare landed cost, approve, and the purchase order goes out automatically.</p>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 py-8 text-center text-sm text-slate-500">
        © {{ date('Y') }} GetL1 · Panchkula, India
    </footer>
</body>
</html>
