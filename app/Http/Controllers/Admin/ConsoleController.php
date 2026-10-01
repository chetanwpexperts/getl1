<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuctionStatus;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Rfq;
use App\Models\Subscription;
use App\Models\SupplierDocument;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GetL1 staff console: business overview, payments, AI usage and logs.
 * Platform admins only, behind the two-step login. Reads across all companies.
 */
class ConsoleController extends Controller
{
    public function dashboard(): View
    {
        $monthStart = now()->setTimezone(config('app.display_timezone'))->startOfMonth()->utc();
        $weekAgo = now()->subDays(7);

        $live = Subscription::with('plan')->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])->get();
        $mrr = $live->sum(fn ($s) => $s->billing_cycle === 'yearly'
            ? (float) $s->plan?->price_yearly / 12 : (float) $s->plan?->price_monthly);

        return view('admin.dashboard', [
            'stats' => [
                'buyers' => Organization::where('type', OrganizationType::Buyer->value)->count(),
                'suppliers' => Organization::where('type', OrganizationType::Supplier->value)->count(),
                'new_week' => Organization::where('created_at', '>=', $weekAgo)->count(),
                'trials' => Subscription::where('status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '>', now())->count(),
                'paid' => $live->count(),
                'past_due' => $live->where('status', SubscriptionStatus::PastDue)->count(),
                'mrr' => round($mrr),
                'revenue_month' => (float) Payment::where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('total'),
                'rfqs_month' => Rfq::withoutGlobalScopes()->where('created_at', '>=', $monthStart)->count(),
                'auctions_month' => Auction::withoutGlobalScopes()->where('status', AuctionStatus::Closed->value)->where('ends_at', '>=', $monthStart)->count(),
                'live_now' => Auction::withoutGlobalScopes()->where('status', AuctionStatus::Live->value)->count(),
                'po_value_month' => (float) Award::withoutGlobalScopes()->whereNotNull('po_sent_at')->where('po_sent_at', '>=', $monthStart)->sum('grand_total'),
                'ai_reads_month' => AiJob::withoutGlobalScopes()->where('created_at', '>=', $monthStart)->where('status', 'done')->count(),
                'ai_cost_month' => (float) AiJob::withoutGlobalScopes()->where('created_at', '>=', $monthStart)->sum('cost_inr'),
                'kyc_pending' => SupplierDocument::where('status', 'pending')->count(),
                'leads_new' => Lead::where('status', 'new')->count(),
            ],
            'plans' => $live->groupBy(fn ($s) => $s->plan?->name ?? 'Unknown')->map->count(),
            'recentOrgs' => Organization::latest()->limit(8)->get(),
            'recentPayments' => Payment::with('organization')->where('status', 'paid')->latest('paid_at')->limit(8)->get(),
        ]);
    }

    public function payments(Request $request): View
    {
        $status = in_array($request->query('status'), ['paid', 'created', 'failed'], true) ? $request->query('status') : 'paid';

        return view('admin.payments', [
            'status' => $status,
            'payments' => Payment::with(['organization', 'plan'])->where('status', $status)->latest('id')->paginate(40)->withQueryString(),
            'totals' => Payment::where('status', 'paid')->selectRaw('kind, count(*) as n, sum(total) as amount')->groupBy('kind')->get()->keyBy('kind'),
        ]);
    }

    public function invoice(Payment $payment, AuditLogger $audit): StreamedResponse
    {
        abort_unless($payment->isPaid() && $payment->invoice_pdf_path && Storage::disk('local')->exists($payment->invoice_pdf_path), 404);
        $audit->log('invoice_viewed_by_admin', $payment, organizationId: $payment->organization_id);

        return Storage::disk('local')->download($payment->invoice_pdf_path, str_replace('/', '-', $payment->invoice_number).'.pdf', [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    public function ai(): View
    {
        $monthStart = now()->setTimezone(config('app.display_timezone'))->startOfMonth()->utc();
        $base = fn () => AiJob::withoutGlobalScopes()->where('created_at', '>=', $monthStart);

        return view('admin.ai', [
            'month' => [
                'done' => $base()->where('status', 'done')->count(),
                'failed' => $base()->where('status', 'failed')->count(),
                'prepaid' => $base()->where('paid_with_credit', true)->where('status', 'done')->count(),
                'cost' => (float) $base()->sum('cost_inr'),
                'tokens_in' => (int) $base()->sum('tokens_in'),
                'tokens_out' => (int) $base()->sum('tokens_out'),
            ],
            'byOrg' => $base()->selectRaw('organization_id, count(*) as n, sum(cost_inr) as cost')
                ->groupBy('organization_id')->orderByDesc('n')->limit(15)->get()
                ->each(fn ($r) => $r->setRelation('organization', Organization::withTrashed()->find($r->organization_id))),
            'jobs' => AiJob::withoutGlobalScopes()->with('organization')->latest('id')->paginate(30),
        ]);
    }

    public function audit(Request $request): View
    {
        $q = AuditLog::query()->with(['user:id,name,email'])->latest('id');
        if ($action = $request->query('action')) {
            $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $action).'%');
        }
        if (is_numeric($org = $request->query('org'))) {
            $q->where('organization_id', (int) $org);
        }

        return view('admin.audit', [
            'logs' => $q->paginate(50)->withQueryString(),
            'orgs' => Organization::withTrashed()->whereIn('id', $q->clone()->limit(50)->pluck('organization_id')->filter())->pluck('name', 'id'),
        ]);
    }

    /** Last entries of the security log (failed logins, lockouts, denied access, rejected uploads, 2FA). */
    public function security(Request $request): View
    {
        $days = collect(File::glob(storage_path('logs/security-*.log')))->sort()->reverse()->values();
        $file = $days->first(fn ($f) => basename($f) === $request->query('file')) ?? $days->first();
        $lines = [];
        if ($file) {
            $content = (string) File::get($file);
            foreach (array_reverse(array_slice(preg_split('/\R/', trim($content)), -400)) as $line) {
                if (preg_match('/^\[(.+?)\] \w+\.(\w+): (\S+) (\{.*\})/', $line, $m)) {
                    $lines[] = ['time' => $m[1], 'level' => strtolower($m[2]), 'event' => $m[3], 'context' => json_decode($m[4], true) ?: []];
                }
            }
        }

        return view('admin.security', ['lines' => $lines, 'files' => $days->map(fn ($f) => basename($f)), 'file' => $file ? basename($file) : null]);
    }
}
