@extends('layouts.app')

@section('title', 'Import suppliers')

@section('content')
    <a href="{{ route('buyer.suppliers.index') }}" class="text-sm text-slate-600 hover:text-slate-900">← Suppliers</a>
    <h1 class="mt-2 text-2xl font-semibold">Import suppliers from Excel</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-600">
        Upload an .xlsx or .csv with columns <b>Company name</b>, <b>Mobile</b> and/or <b>Email</b>
        (optional: Contact name, Tag, Notes). Up to 500 rows. Suppliers already in your list are skipped.
    </p>

    <div class="mt-6 grid max-w-3xl gap-6 sm:grid-cols-[1fr_220px]">
        <form method="POST" action="{{ route('buyer.suppliers.import.store') }}" enctype="multipart/form-data"
              class="space-y-4 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
            @csrf
            <div>
                <label for="file" class="block text-sm font-medium text-slate-700">Spreadsheet (max 1 MB)</label>
                <input id="file" name="file" type="file" required accept=".xlsx,.csv"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5">
                @error('file') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:w-48"><x-button>Import</x-button></div>
        </form>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 text-sm">
            <p class="font-medium">Not sure about the format?</p>
            <p class="mt-1 text-slate-600">Download the template, fill it in Excel, save and upload.</p>
            <a href="{{ route('buyer.suppliers.template') }}" class="mt-3 inline-block font-medium text-emerald-700 hover:underline">Download template (.csv)</a>
        </div>
    </div>
@endsection
