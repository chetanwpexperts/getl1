<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Enums\OrganizationType;
use App\Enums\RfqStatus;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\RfqInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier side of an invite.
 *
 * An invite link is a bearer token, so on first use it is bound to exactly one supplier company,
 * and only a user whose email or mobile matches the contact the buyer entered can claim it.
 * A forwarded link can't be claimed by someone else.
 */
class InviteService
{
    public function __construct(private AuditLogger $audit) {}

    public function findByToken(string $token): ?RfqInvite
    {
        if (! preg_match('/^[A-Za-z0-9]{40,64}$/', $token)) {
            return null;
        }

        return RfqInvite::with(['rfq.organization', 'listEntry'])->where('token', $token)->first();
    }

    /** The supplier company this user acts for (current org if it's a supplier, else their first supplier org). */
    public function supplierOrgFor(User $user): ?Organization
    {
        $current = $user->currentOrganization;
        if ($current && $current->isSupplier() && $user->belongsToOrganization($current)) {
            return $current;
        }

        return $user->organizations()->where('type', OrganizationType::Supplier->value)->first();
    }

    /**
     * Bind the invite to the user's supplier company, or confirm it already is.
     *
     * @return array{ok: bool, reason?: string, hint?: string}
     */
    public function claim(RfqInvite $invite, User $user): array
    {
        $supplier = $this->supplierOrgFor($user);
        if (! $supplier) {
            return ['ok' => false, 'reason' => 'not_supplier'];
        }

        if ($invite->supplier_org_id) {
            if ($invite->supplier_org_id === $supplier->id) {
                return ['ok' => true];
            }
            SecurityLog::warning('invite_claim_denied', ['invite_id' => $invite->id, 'reason' => 'bound_to_other_supplier', 'supplier_org_id' => $supplier->id]);

            return ['ok' => false, 'reason' => 'other_supplier'];
        }

        $entry = $invite->listEntry;
        $byEmail = $entry && $entry->contact_email && strcasecmp($entry->contact_email, $user->email) === 0;
        $byPhone = ! $byEmail && $entry && $entry->contact_phone && $user->phone && $entry->contact_phone === $user->phone;
        $matches = $byEmail || $byPhone;

        if (! $matches) {
            SecurityLog::warning('invite_claim_denied', ['invite_id' => $invite->id, 'reason' => 'contact_mismatch', 'supplier_org_id' => $supplier->id]);

            return ['ok' => false, 'reason' => 'contact_mismatch', 'hint' => $this->maskedContact($entry)];
        }

        // The same supplier may already hold another invite on this RFQ (added twice under different names).
        if (RfqInvite::where('rfq_id', $invite->rfq_id)->where('supplier_org_id', $supplier->id)->exists()) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }

        DB::transaction(function () use ($invite, $supplier, $entry, $user, $byPhone) {
            $invite->update(['supplier_org_id' => $supplier->id]);

            $linkedElsewhere = BuyerSupplier::where('buyer_org_id', $entry->buyer_org_id)->where('supplier_org_id', $supplier->id)->exists();
            if (! $entry->supplier_org_id && ! $linkedElsewhere) {
                $entry->update(['supplier_org_id' => $supplier->id]);
            }

            $this->audit->log('rfq_invite_claimed', $invite, after: ['supplier_org_id' => $supplier->id, 'matched_by' => $byPhone ? 'phone' : 'email'], user: $user,
                organizationId: $invite->rfq->organization_id);
        });
        // Mobile numbers aren't verified by OTP yet: when the match was by mobile, the buyer is told
        // which company took the invite, so a wrong claim is spotted straight away.
        if ($byPhone) {
            $rfq = $invite->rfq;
            Notifier::toUsers(app(Automations::class)->buyerTeam($rfq), $rfq->organization_id, 'sourcing', "Invitation taken: {$rfq->ref_no}",
                "{$supplier->name} ({$user->name}, mobile ending ".substr((string) $user->phone, -4).") opened the invitation sent to {$entry->company_name}. If that's not right, block them in Suppliers.",
                route('buyer.rfqs.show', $rfq->id));
        }

        return ['ok' => true];
    }

    public function accept(RfqInvite $invite, User $user): void
    {
        $this->assertOpen($invite);
        if ($invite->status === InviteStatus::Accepted) {
            return;
        }

        $invite->update(['status' => InviteStatus::Accepted, 'accepted_terms_at' => now(), 'declined_at' => null]);
        $this->audit->log('rfq_invite_accepted', $invite, user: $user, organizationId: $invite->rfq->organization_id);
    }

    public function decline(RfqInvite $invite, User $user, ?string $reason): void
    {
        $this->assertOpen($invite);
        if ($invite->rfq->quotes()->where('supplier_org_id', $invite->supplier_org_id)->exists()) {
            throw ValidationException::withMessages(['reason' => 'You have already quoted. Contact the buyer if you need to withdraw.']);
        }

        $invite->update(['status' => InviteStatus::Declined, 'declined_at' => now()]);
        $this->audit->log('rfq_invite_declined', $invite, after: ['reason' => $reason], user: $user, organizationId: $invite->rfq->organization_id);
    }

    public function assertOpen(RfqInvite $invite): void
    {
        if (! $invite->rfq->isOpenForQuotes()) {
            throw ValidationException::withMessages(['rfq' => $invite->rfq->status === RfqStatus::Cancelled
                ? 'The buyer cancelled this RFQ.' : 'The deadline for this RFQ has passed.']);
        }
    }

    /** "r***@sharma.in" / "******3210", enough for the right person to recognise it. */
    private function maskedContact(?BuyerSupplier $entry): ?string
    {
        if (! $entry) {
            return null;
        }
        if ($entry->contact_email) {
            [$local, $domain] = explode('@', $entry->contact_email, 2) + [1 => ''];

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return $entry->contact_phone ? str_repeat('*', 6).substr($entry->contact_phone, -4) : null;
    }
}
