<?php

namespace App\Http\Controllers;

use App\Enums\OrgRole;
use App\Models\Category;
use App\Models\Organization;
use App\Rules\Gstin;
use App\Rules\Pan;
use App\Rules\Udyam;
use App\Services\AuditLogger;
use App\Support\IndianIds;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Company profile for the current organization (buyer or supplier).
 * Changing identity fields (name, GSTIN, PAN) on a verified supplier removes the
 * verified badge until an admin re-checks the documents.
 */
class CompanyProfileController extends Controller
{
    private const IDENTITY_FIELDS = ['name', 'gstin', 'pan'];

    public function edit(CurrentOrganization $current): View
    {
        $org = $current->get()->load('categories');

        return view('company.profile', [
            'org' => $org,
            'canEdit' => $this->canEdit($org),
            'categories' => $org->isSupplier()
                ? Category::whereNull('parent_id')->with('children')->orderBy('sort')->get()
                : collect(),
            'selected' => $org->categories->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, CurrentOrganization $current, AuditLogger $audit): RedirectResponse
    {
        $org = $current->get();
        abort_unless($this->canEdit($org), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'gstin' => ['nullable', 'string', 'size:15', new Gstin,
                // One GSTIN = one company on GetL1 (stops someone posing as another firm)
                Rule::unique('organizations', 'gstin')->ignore($org->id)->whereNull('deleted_at')],
            'pan' => ['nullable', 'string', 'size:10', new Pan],
            'udyam_no' => ['nullable', 'string', 'max:19', new Udyam],
            'award_approval_limit' => [Rule::excludeIf(! $org->isBuyer()), 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'po_terms' => [Rule::excludeIf(! $org->isBuyer()), 'nullable', 'string', 'max:3000'],
            'categories' => ['array', 'max:20'],
            'categories.*' => ['integer', Rule::exists('categories', 'id')->whereNotNull('parent_id')],
        ], [
            'phone.regex' => 'Enter a 10-digit Indian mobile number.',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
            'gstin.unique' => 'This GSTIN is already registered with another company. Contact support if this is yours.',
        ]);

        foreach (['gstin', 'pan', 'udyam_no'] as $f) {
            $data[$f] = isset($data[$f]) ? strtoupper(trim($data[$f])) : null;
        }

        if ($data['gstin'] && $data['pan'] && IndianIds::panFromGstin($data['gstin']) !== $data['pan']) {
            return back()->withInput()->withErrors(['pan' => 'PAN does not match the PAN inside your GSTIN.']);
        }

        $fields = collect($data)->except('categories')->all();
        $before = $org->only(array_keys($fields));
        $org->fill($fields);

        $identityChanged = $org->isDirty(self::IDENTITY_FIELDS);
        if ($identityChanged && $org->isVerified()) {
            $org->verified_at = null;
        }
        $udyamChanged = $org->isDirty('udyam_no');
        $org->save();
        if ($udyamChanged && $org->isSupplier()) {
            // MSME status changed: unpaid invoices get their legal due date re-worked.
            \App\Services\Payables\MsmeDueDate::refreshSupplier($org->id);
        }

        if ($org->isSupplier()) {
            $org->categories()->sync($data['categories'] ?? []);
        }

        $audit->log('company_profile_updated', $org,
            before: $before,
            after: $fields + ['categories' => $data['categories'] ?? []]);

        if ($identityChanged && $org->wasChanged('verified_at')) {
            $audit->log('verification_reset', $org, after: ['reason' => 'identity fields changed']);

            return redirect()->route('company.edit')
                ->with('status', 'Profile saved. Your verified badge was removed because company name/GSTIN/PAN changed. We will re-check your documents.');
        }

        return redirect()->route('company.edit')->with('status', 'Profile saved.');
    }

    private function canEdit(Organization $org): bool
    {
        $role = auth()->user()->roleIn($org);

        return $org->isSupplier() || $role === OrgRole::BuyerAdmin;
    }
}
