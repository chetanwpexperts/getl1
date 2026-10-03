<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Invoices and payments across all POs, with the MSME 45-day view. SupplierInvoice is scoped to the
 * buyer's company by its global scope.
 */
class PaymentController extends Controller
{
    public const TABS = ['due' => 'To pay', 'review' => 'To review', 'paid' => 'Paid', 'disputed' => 'Disputed'];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'due';
        $base = SupplierInvoice::query()->with(['supplier:id,name,udyam_no', 'award:id,po_number,rfq_id']);
        $today = now()->setTimezone(config('app.display_timezone'))->toDateString();
        $outstanding = (clone $base)->whereIn('status', [SupplierInvoice::SUBMITTED, SupplierInvoice::APPROVED]);

        $list = match ($tab) {
            'review' => (clone $base)->where('status', SupplierInvoice::SUBMITTED)->orderBy('due_date'),
            'paid' => (clone $base)->where('status', SupplierInvoice::PAID)->orderByDesc('paid_on'),
            'disputed' => (clone $base)->where('status', SupplierInvoice::DISPUTED)->orderByDesc('reviewed_at'),
            default => (clone $base)->where('status', SupplierInvoice::APPROVED)->orderBy('due_date'),
        };

        return view('buyer.payments.index', [
            'tab' => $tab,
            'invoices' => $list->paginate(25)->withQueryString(),
            'counts' => [
                'due' => (clone $base)->where('status', SupplierInvoice::APPROVED)->count(),
                'review' => (clone $base)->where('status', SupplierInvoice::SUBMITTED)->count(),
            ],
            'stats' => [
                'msme_overdue' => (float) (clone $outstanding)->where('is_msme', true)->where('due_date', '<', $today)->sum('total_amount'),
                'msme_overdue_n' => (clone $outstanding)->where('is_msme', true)->where('due_date', '<', $today)->count(),
                'due_week' => (float) (clone $outstanding)->whereBetween('due_date', [$today, now()->setTimezone(config('app.display_timezone'))->addDays(7)->toDateString()])->sum('total_amount'),
                'outstanding' => (float) (clone $outstanding)->sum('total_amount'),
            ],
            'canEdit' => $request->user()->hasRoleIn(app(\App\Support\Tenancy\CurrentOrganization::class)->get(), 'buyer_admin', 'buyer_user'),
        ]);
    }
}
