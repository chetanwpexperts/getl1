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
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GetL1 staff console: business overview, payments, AI usage and logs.
 * Platform admins only, behind the two-step login. Reads across all companies.
 */
class ConsoleController extends Controller
{
    public function dashboard(Request $request): View
    {
        $d = new \App\Services\Admin\Dashboard((string) $request->query('period', '30d'), $request->boolean('test'));
        $checks = app(\App\Services\SystemHealth::class)->checks();

        return view('admin.dashboard', [
            'd' => $d,
            'kpis' => $d->kpis(),
            'charts' => $d->charts(),
            'categories' => $d->categories(),
            'topBuyers' => $d->topBuyers(),
            'topSuppliers' => $d->topSuppliers(),
            'live' => $d->liveAndNext(),
            'activity' => $activity = $d->activity(),
            'activityOrgs' => Organization::withTrashed()->whereIn('id', $activity->pluck('organization_id')->filter())->pluck('name', 'id'),
            'includeTest' => $request->boolean('test'),
            'health' => \App\Services\SystemHealth::overall($checks),
            'healthIssues' => collect($checks)->where('status', '!=', 'ok')->pluck('label')->all(),
            'attention' => [
                'kyc' => SupplierDocument::where('status', 'pending')->count(),
                'leads' => Lead::where('status', 'new')->count(),
                'past_due' => Subscription::where('status', SubscriptionStatus::PastDue->value)->count(),
                'paused' => Auction::withoutGlobalScopes()->whereNotNull('paused_at')->where('status', AuctionStatus::Live->value)->count(),
            ],
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
    public function security(Request $request, \App\Services\SecurityLogReader $reader): View
    {
        $files = collect($reader->files());
        $file = $files->contains($request->query('file')) ? $request->query('file') : $files->first();

        return view('admin.security', ['lines' => $reader->entries($file), 'files' => $files, 'file' => $file]);
    }

    public function health(\App\Services\SystemHealth $health, \App\Services\PlatformSettings $settings): View
    {
        $checks = $health->checks();

        return view('admin.health', [
            'checks' => $checks,
            'overall' => \App\Services\SystemHealth::overall($checks),
            'alertTo' => $settings->get('alerts.email') ?: config('site.leads_to'),
        ]);
    }
}
