<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRule;
use App\Services\ApprovalFlow;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A buyer company's approval levels for awards. Admins set them; the rules in force when an award
 * is made decide its approvals (later changes never change an award already waiting).
 */
class ApprovalRuleController extends Controller
{
    public function __construct(private CurrentOrganization $current, private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $org = $this->current->get();

        return view('buyer.approval-rules', [
            'rules' => ApprovalRule::with('approver:id,name')->orderBy('position')->orderBy('id')->get(),
            'approvers' => $org->users()->wherePivotIn('role', array_map(fn ($r) => $r->value, ApprovalFlow::DECIDER_ROLES))->orderBy('name')->get(),
            'isAdmin' => $request->user()->hasRoleIn($org, 'buyer_admin'),
            'legacyLimit' => $org->award_approval_limit,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (ApprovalRule::count() >= ApprovalRule::MAX) {
            throw ValidationException::withMessages(['name' => 'You can have up to '.ApprovalRule::MAX.' approval levels.']);
        }
        $data = $this->validated($request);
        $rule = ApprovalRule::create($data + ['organization_id' => $this->current->id(), 'position' => (int) ApprovalRule::max('position') + 1]);
        $this->audit->log('approval_rule_added', $rule, after: $this->snapshot($rule));

        return redirect()->route('buyer.approval-rules.index')->with('status', "Level \"{$rule->name}\" added.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $rule = ApprovalRule::findOrFail($id);
        $before = $this->snapshot($rule);
        $data = $this->validated($request, 'rule'.$id.'_');
        $rule->update($data);
        if ($request->filled('move')) {
            $this->move($rule, $request->input('move') === 'up' ? -1 : 1);
        }
        $this->audit->log('approval_rule_changed', $rule, before: $before, after: $this->snapshot($rule->fresh()));

        return redirect()->route('buyer.approval-rules.index')->with('status', "Level \"{$rule->name}\" saved.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $rule = ApprovalRule::findOrFail($id);
        $this->audit->log('approval_rule_removed', $rule, before: $this->snapshot($rule));
        $rule->delete();
        // Keep positions 1..n.
        ApprovalRule::orderBy('position')->orderBy('id')->get()->values()->each(fn ($r, $i) => $r->update(['position' => $i + 1]));

        return redirect()->route('buyer.approval-rules.index')->with('status', "Level \"{$rule->name}\" removed. Awards already waiting keep their approvals.");
    }

    private function move(ApprovalRule $rule, int $dir): void
    {
        $all = ApprovalRule::orderBy('position')->orderBy('id')->get()->values();
        $i = $all->search(fn ($r) => $r->id === $rule->id);
        $j = $i + $dir;
        if ($i === false || $j < 0 || $j >= $all->count()) {
            return;
        }
        $order = $all->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        foreach (array_values($order) as $k => $r) {
            $r->update(['position' => $k + 1]);
        }
    }

    private function validated(Request $request, string $prefix = ''): array
    {
        $in = fn (string $k) => $request->input($prefix.$k);
        $raw = [
            'name' => Str::squish((string) $in('name')),
            'min_amount' => $in('min_amount') === null || $in('min_amount') === '' ? null : str_replace(',', '', (string) $in('min_amount')),
            'when_not_l1' => (bool) $in('when_not_l1'),
            'when_single_quote' => (bool) $in('when_single_quote'),
            'when_new_supplier' => (bool) $in('when_new_supplier'),
            'approver_user_id' => $in('approver_user_id') ?: null,
        ];
        $v = validator($raw, [
            'name' => ['required', 'string', 'max:80'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'approver_user_id' => ['nullable', 'integer'],
        ], [], ['name' => 'level name', 'min_amount' => 'amount']);
        $v->after(function ($v) use ($raw) {
            if ($raw['min_amount'] === null && ! $raw['when_not_l1'] && ! $raw['when_single_quote'] && ! $raw['when_new_supplier']) {
                $v->errors()->add('min_amount', 'Give an amount (0 for every award) or tick at least one condition.');
            }
            if ($raw['approver_user_id'] && ! $this->current->get()->users()->whereKey($raw['approver_user_id'])
                ->wherePivotIn('role', array_map(fn ($r) => $r->value, ApprovalFlow::DECIDER_ROLES))->exists()) {
                $v->errors()->add('approver_user_id', 'Choose an approver or admin from your team.');
            }
        });
        $data = $v->validate();

        return ['name' => $data['name'], 'min_amount' => $data['min_amount'], 'approver_user_id' => $data['approver_user_id'] ? (int) $data['approver_user_id'] : null,
            'when_not_l1' => $raw['when_not_l1'], 'when_single_quote' => $raw['when_single_quote'], 'when_new_supplier' => $raw['when_new_supplier']];
    }

    private function snapshot(ApprovalRule $r): array
    {
        return $r->only(['name', 'position', 'min_amount', 'when_not_l1', 'when_single_quote', 'when_new_supplier', 'approver_user_id']);
    }
}
