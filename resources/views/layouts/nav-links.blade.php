@isset($currentOrg)
    <a href="{{ route('dashboard') }}" class="whitespace-nowrap hover:text-slate-900 {{ request()->routeIs('dashboard') ? 'text-slate-900' : '' }}">Dashboard</a>
    @if ($currentOrg->isBuyer())
        <a href="{{ route('buyer.suppliers.index') }}" class="whitespace-nowrap hover:text-slate-900 {{ request()->routeIs('buyer.suppliers.*') ? 'text-slate-900' : '' }}">Suppliers</a>
    @else
        <a href="{{ route('supplier.documents.index') }}" class="whitespace-nowrap hover:text-slate-900 {{ request()->routeIs('supplier.documents.*') ? 'text-slate-900' : '' }}">Documents</a>
    @endif
    <a href="{{ route('company.edit') }}" class="whitespace-nowrap hover:text-slate-900 {{ request()->routeIs('company.*') ? 'text-slate-900' : '' }}">Company</a>
@endisset
@if (auth()->user()?->is_platform_admin)
    <a href="{{ route('admin.kyc.index') }}" class="whitespace-nowrap text-amber-700 hover:text-amber-900">Admin · KYC</a>
@endif
