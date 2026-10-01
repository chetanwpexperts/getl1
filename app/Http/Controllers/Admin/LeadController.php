<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Early-access and demo requests from the website: follow up and track status. */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), Lead::STATUSES) ? $request->query('status') : null;

        return view('admin.leads', [
            'leads' => Lead::when($status, fn ($q) => $q->where('status', $status))->latest()->paginate(40)->withQueryString(),
            'status' => $status,
            'counts' => Lead::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function update(Request $request, Lead $lead, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Lead::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $before = $lead->only(['status']);
        $lead->update($data);
        $audit->log('admin_lead_updated', $lead, before: $before, after: ['status' => $lead->status], organizationId: null);

        return back()->with('status', "Saved {$lead->company}.");
    }
}
