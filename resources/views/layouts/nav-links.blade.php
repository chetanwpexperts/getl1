@php $link = 'whitespace-nowrap hover:text-slate-900'; @endphp
@php $active = 'text-slate-900'; @endphp
@isset($currentOrg)
    <a href="{{ route('dashboard') }}" class="{{ $link }} {{ request()->routeIs('dashboard') ? $active : '' }}">Dashboard</a>
    @if ($currentOrg->isBuyer())
        <a href="{{ route('buyer.rfqs.index') }}" class="{{ $link }} {{ request()->routeIs('buyer.rfqs.*') ? $active : '' }}">RFQs</a>
        <a href="{{ route('buyer.suppliers.index') }}" class="{{ $link }} {{ request()->routeIs('buyer.suppliers.*') ? $active : '' }}">Suppliers</a>
        <a href="{{ route('buyer.reports.savings') }}" class="{{ $link }} {{ request()->routeIs('buyer.reports.*') ? $active : '' }}">Savings</a>
        <a href="{{ route('buyer.billing.index') }}" class="{{ $link }} {{ request()->routeIs('buyer.billing.*') ? $active : '' }}">Billing</a>
        @if (in_array($currentRole?->value, ['buyer_admin', 'approver'], true))
            <a href="{{ route('buyer.approvals.index') }}" class="{{ $link }} {{ request()->routeIs('buyer.approvals.*') ? $active : '' }}">
                Approvals @if (($pendingApprovals ?? 0) > 0)<span class="ml-0.5 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $pendingApprovals }}</span>@endif
            </a>
        @endif
    @else
        <a href="{{ route('supplier.rfqs.index') }}" class="{{ $link }} {{ request()->routeIs('supplier.rfqs.*') ? $active : '' }}">RFQs</a>
        <a href="{{ route('supplier.orders.index') }}" class="{{ $link }} {{ request()->routeIs('supplier.orders.*') ? $active : '' }}">
            Orders @if (($openOrders ?? 0) > 0)<span class="ml-0.5 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $openOrders }}</span>@endif
        </a>
        <a href="{{ route('supplier.documents.index') }}" class="{{ $link }} {{ request()->routeIs('supplier.documents.*') ? $active : '' }}">Documents</a>
    @endif
    <a href="{{ route('company.edit') }}" class="{{ $link }} {{ request()->routeIs('company.*') ? $active : '' }}">Company</a>
@endisset
@if (auth()->user()?->is_platform_admin)
    <a href="{{ route('admin.dashboard') }}" class="{{ $link }} {{ request()->routeIs('admin.*') ? $active : '' }}">
        Admin console <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Staff</span>
    </a>
@endif
