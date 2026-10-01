<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Models\Auction;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Rfq;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\Billing\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff view of customer companies, with the few manual actions support needs:
 * extend a trial, add credits, suspend or restore. Every action needs a reason and is audit-logged.
 */
class CompanyController extends Controller
{
    public function __construct(private AuditLogger $audit, private PlanService $plans) {}

    public function index(Request $request): View
    {
        $type = in_array($request->query('type'), ['buyer', 'supplier'], true) ? $request->query('type') : null;
        $q = trim((string) $request->query('q'));

        $orgs = Organization::query()
            ->when($type, fn ($b) => $b->where('type', $type))
            ->when($request->query('status') === 'suspended', fn ($b) => $b->where('status', 'suspended'))
            ->when($q !== '', function ($b) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $b->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('gstin', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like));
            })
            ->withCount('users')
            ->latest()
            ->paginate(30)->withQueryString();

        return view('admin.companies.index', [
            'orgs' => $orgs,
            'type' => $type,
            'q' => $q,
            'plansByOrg' => $orgs->getCollection()->filter->isBuyer()->mapWithKeys(fn ($o) => [$o->id => $this->plans->liveSubscription($o)]),
        ]);
    }

    public function show(Organization $organization): View
    {
        $o = $organization->load('users');
        $isBuyer = $o->isBuyer();

        return view('admin.companies.show', [
            'org' => $o,
            'live' => $isBuyer ? $this->plans->liveSubscription($o) : null,
            'plan' => $isBuyer ? $this->plans->current($o) : null,
            'auctions' => $isBuyer ? $this->plans->auctionAllowance($o) : null,
            'ai' => $isBuyer ? $this->plans->aiAllowance($o) : null,
            'counts' => $isBuyer ? [
                'RFQs' => Rfq::withoutGlobalScopes()->where('organization_id', $o->id)->count(),
                'Auctions' => Auction::withoutGlobalScopes()->where('organization_id', $o->id)->count(),
                'Purchase orders' => Award::withoutGlobalScopes()->where('organization_id', $o->id)->whereNotNull('po_number')->count(),
                'AI reads' => AiJob::withoutGlobalScopes()->where('organization_id', $o->id)->where('status', 'done')->count(),
            ] : [
                'Invitations' => DB::table('rfq_invites')->where('supplier_org_id', $o->id)->count(),
                'Quotes' => DB::table('quotes')->where('supplier_org_id', $o->id)->count(),
                'Orders won' => Award::withoutGlobalScopes()->where('supplier_org_id', $o->id)->whereNotNull('po_number')->count(),
            ],
            'documents' => $o->isSupplier() ? \App\Models\SupplierDocument::where('organization_id', $o->id)->latest()->get() : collect(),
            'payments' => Payment::where('organization_id', $o->id)->latest('id')->limit(20)->get(),
            'activity' => AuditLog::with('user:id,name,email')->where('organization_id', $o->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function extendTrial(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($organization->isBuyer(), 404);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:60'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);

        $live = $this->plans->liveSubscription($organization);
        if ($live && $live->status !== SubscriptionStatus::Trialing) {
            return back()->withErrors(['days' => 'This company is on a paid plan. Add credits instead.']);
        }

        $sub = DB::transaction(function () use ($organization, $live, $data) {
            if ($live) {
                $before = $live->trial_ends_at;
                $live->update(['trial_ends_at' => $live->trial_ends_at->copy()->addDays($data['days'])]);

                return [$live, $before];
            }
            $plan = Plan::where('code', PlanService::TRIAL_PLAN)->firstOrFail();

            return [$organization->subscriptions()->create([
                'plan_id' => $plan->id, 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays($data['days']),
            ]), null];
        });
        [$s, $before] = $sub;
        $this->audit->log('admin_trial_extended', $s, before: ['trial_ends_at' => $before?->toIso8601String()],
            after: ['trial_ends_at' => $s->trial_ends_at->toIso8601String(), 'days' => $data['days'], 'reason' => $data['reason']], organizationId: $organization->id);

        return back()->with('status', 'Trial now ends '.$s->trial_ends_at->ist()->format('d M Y').'.');
    }

    public function grantCredits(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($organization->isBuyer(), 404);
        $data = $request->validate([
            'kind' => ['required', 'in:auction_credits,ai_credits'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $before = (int) $organization->{$data['kind']};
        Organization::whereKey($organization->id)->increment($data['kind'], $data['quantity']);
        $this->audit->log('admin_credits_granted', $organization, before: [$data['kind'] => $before],
            after: [$data['kind'] => $before + $data['quantity'], 'reason' => $data['reason']], organizationId: $organization->id);

        $label = $data['kind'] === 'ai_credits' ? 'AI reads' : 'auction credits';

        return back()->with('status', "Added {$data['quantity']} {$label}.");
    }

    public function suspend(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        abort_if($organization->status === 'suspended', 409);
        $organization->update(['status' => 'suspended']);
        $this->audit->log('admin_company_suspended', $organization, before: ['status' => 'active'],
            after: ['status' => 'suspended', 'reason' => $data['reason']], organizationId: $organization->id);
        \App\Services\SecurityLog::warning('admin_company_suspended', ['organization_id' => $organization->id]);

        return back()->with('status', "{$organization->name} is suspended. Its users can't use GetL1 until restored.");
    }

    public function restore(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        abort_unless($organization->status === 'suspended', 409);
        $organization->update(['status' => 'active']);
        $this->audit->log('admin_company_restored', $organization, before: ['status' => 'suspended'],
            after: ['status' => 'active', 'reason' => $data['reason']], organizationId: $organization->id);

        return back()->with('status', "{$organization->name} is active again.");
    }
}
