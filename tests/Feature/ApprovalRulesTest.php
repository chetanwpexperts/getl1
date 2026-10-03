<?php

namespace Tests\Feature;

use App\Enums\AwardStatus;
use App\Enums\OrgRole;
use App\Enums\RfqStatus;
use App\Mail\AwardApprovalRequestMail;
use App\Mail\AwardDecisionMail;
use App\Mail\NotSelectedMail;
use App\Mail\PoAcceptedMail;
use App\Mail\PurchaseOrderMail;
use App\Models\AuditLog;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Models\ApprovalRule;
use App\Models\AwardApprovalStep;
use App\Services\RfqService;
use App\Support\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Rule-based, multi-level award approvals. */
class ApprovalRulesTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-rules-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));

        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        $this->buyer->update(['gstin' => null, 'state' => 'Haryana', 'address' => 'Sector 8', 'city' => 'Panchkula']);
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Boxes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
        $this->rfq = $this->publishedRfq($this->buyer, $this->buyerUser);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function publishedRfq(Organization $buyer, User $user): Rfq
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($buyer, $user, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [
                ['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs', 'last_purchase_price' => 100],
                ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll'],
            ],
        ]);
        $svc->invite($rfq, $user, BuyerSupplier::where('buyer_org_id', $buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $user);
        app(CurrentOrganization::class)->set(null);

        return $rfq->fresh();
    }

    /** Submit a sealed quote through the real screens: [box price, tape price]. */
    private function quote(string $k, array $prices, ?Rfq $rfq = null): void
    {
        $rfq ??= $this->rfq;
        $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
        $items = [];
        foreach ($rfq->items()->orderBy('line_no')->get() as $i => $item) {
            $items[$item->id] = ['unit_price' => $prices[$i], 'gst_rate' => '18', 'freight' => 0];
        }
        $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), [
            'items' => $items, 'valid_till' => now()->addDays(15)->toDateString(),
        ])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
    }

    /** A: 90+20 → 94,000; B: 85+30 → 91,000 (L1); C: 95+10 → 97,000. */
    private function threeQuotesAndDeadline(): void
    {
        $this->quote('A', [90, 20]);
        $this->quote('B', [85, 30]);
        $this->quote('C', [95, 10]);
        $this->travelTo($this->t0->copy()->addHours(4));
    }

    private function awardTo(string $k, array $extra = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->buyerUser)->post(route('buyer.awards.store', $this->rfq->id),
            ['supplier_org_id' => $this->s[$k][0]->id] + $extra);
    }

    private function award(): Award
    {
        return Award::withoutGlobalScopes()->where('rfq_id', $this->rfq->id)->latest('id')->firstOrFail();
    }

    private User $manager;
    private User $director;

    private function rules(): void
    {
        $this->manager = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->director = $this->memberOf($this->buyer, OrgRole::BuyerAdmin);
        $this->actingAs($this->buyerUser); // owner is admin in these tests
        foreach ([
            ['name' => 'Purchase manager', 'min_amount' => '0', 'approver_user_id' => $this->manager->id],
            ['name' => 'Director', 'min_amount' => '93000', 'when_not_l1' => '1', 'approver_user_id' => $this->director->id],
        ] as $r) {
            $this->post(route('buyer.approval-rules.store'), $r)->assertSessionHasNoErrors();
        }
        app(CurrentOrganization::class)->set(null);
    }

    private function titles(User $u): array
    {
        return $u->fresh()->notifications()->get()->pluck('data.title')->all();
    }

    public function test_award_to_l1_below_the_director_amount_needs_one_level(): void
    {
        $this->rules();
        $this->threeQuotesAndDeadline();
        $this->awardTo('B')->assertSessionHasNoErrors(); // 91,000, L1
        $a = $this->award();
        $this->assertSame(AwardStatus::PendingApproval, $a->status);
        $this->assertSame(['Purchase manager'], AwardApprovalStep::withoutGlobalScopes()->where('award_id', $a->id)->pluck('name')->all());

        // Only the named manager decides; the director has no part in this one.
        $this->actingAs($this->director)->post(route('buyer.awards.approve', $a->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('buyer.awards.approve', $a->id))->assertRedirect();
        $this->assertSame(AwardStatus::PoSent, $a->fresh()->status);
    }

    public function test_not_l1_goes_through_both_levels_in_order(): void
    {
        $this->rules();
        $this->threeQuotesAndDeadline();
        $this->awardTo('A', ['reason' => 'Better delivery record last quarter'])->assertSessionHasNoErrors(); // 94,000, not L1
        $a = $this->award();
        $steps = AwardApprovalStep::withoutGlobalScopes()->where('award_id', $a->id)->orderBy('position')->get();
        $this->assertSame(['Purchase manager', 'Director'], $steps->pluck('name')->all());
        $this->assertStringContainsString('Not given to L1', $steps[1]->why);
        Mail::assertQueued(AwardApprovalRequestMail::class, fn ($m) => $m->hasTo($this->manager->email));
        Mail::assertNotQueued(AwardApprovalRequestMail::class, fn ($m) => $m->hasTo($this->director->email));

        // Director can't jump ahead.
        $this->actingAs($this->director)->post(route('buyer.awards.approve', $a->id))->assertForbidden();
        $this->actingAs($this->manager)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Approve, send to next level');
        $this->actingAs($this->manager)->post(route('buyer.awards.approve', $a->id), ['decision_note' => 'Fine by me'])->assertRedirect();
        app(CurrentOrganization::class)->set(null);
        $this->assertSame(AwardStatus::PendingApproval, $a->fresh()->status); // still waiting
        Mail::assertNotQueued(PurchaseOrderMail::class);
        Mail::assertQueued(AwardApprovalRequestMail::class, fn ($m) => $m->hasTo($this->director->email));
        $this->assertContains('Award waiting for your approval (Director)', $this->titles($this->director));
        $this->assertContains('Purchase manager approved: '.$this->rfq->ref_no, $this->titles($this->buyerUser));
        // The manager doesn't approve a second level.
        $this->actingAs($this->manager)->post(route('buyer.awards.approve', $a->id))->assertForbidden();

        $this->actingAs($this->director)->get(route('buyer.approvals.index'))->assertOk()->assertSee('Director')->assertSee('Review');
        $this->actingAs($this->director)->post(route('buyer.awards.approve', $a->id))->assertRedirect();
        $a->refresh();
        $this->assertSame(AwardStatus::PoSent, $a->status);
        $this->assertSame($this->director->id, $a->approved_by);
        $this->assertSame(['approved', 'approved'], AwardApprovalStep::withoutGlobalScopes()->orderBy('position')->pluck('status')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'award_level_approved']);
    }

    public function test_a_no_at_any_level_rejects_the_award(): void
    {
        $this->rules();
        $this->threeQuotesAndDeadline();
        $this->awardTo('A', ['reason' => 'Better delivery record last quarter']);
        $a = $this->award();
        $this->actingAs($this->manager)->post(route('buyer.awards.reject', $a->id), ['decision_note' => 'Use L1, quality is fine'])->assertRedirect();
        $this->assertSame(AwardStatus::Rejected, $a->fresh()->status);
        $this->assertSame(['rejected', 'cancelled'], AwardApprovalStep::withoutGlobalScopes()->orderBy('position')->pluck('status')->all());
        $this->assertSame(RfqStatus::Evaluating, $this->rfq->fresh()->status);
    }

    public function test_conditions_only_and_rules_changed_later_dont_touch_waiting_awards(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($this->buyerUser)->post(route('buyer.approval-rules.store'), ['name' => 'New supplier check', 'when_new_supplier' => '1'])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->threeQuotesAndDeadline();
        $this->awardTo('B');
        $a = $this->award();
        $this->assertSame('First order with this supplier', AwardApprovalStep::withoutGlobalScopes()->value('why'));

        // Rules removed while it waits: it keeps its level.
        $this->actingAs($this->buyerUser)->delete(route('buyer.approval-rules.destroy', ApprovalRule::withoutGlobalScopes()->value('id')))->assertRedirect();
        app(CurrentOrganization::class)->set(null);
        $this->actingAs($approver)->post(route('buyer.awards.approve', $a->id))->assertRedirect();
        $this->assertSame(AwardStatus::PoSent, $a->fresh()->status);
    }

    public function test_rules_page_only_admins_change_it(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $plain = $this->memberOf($this->buyer, OrgRole::BuyerUser);
        $this->actingAs($this->buyerUser)->get(route('buyer.approval-rules.index'))->assertOk()->assertSee('No approval rules yet')->assertSee('Add a level');
        // Needs an amount or a condition; named approver must have approval rights.
        $this->post(route('buyer.approval-rules.store'), ['name' => 'Empty'])->assertSessionHasErrors('min_amount');
        $this->post(route('buyer.approval-rules.store'), ['name' => 'X', 'min_amount' => '1000', 'approver_user_id' => $plain->id])->assertSessionHasErrors('approver_user_id');
        $this->post(route('buyer.approval-rules.store'), ['name' => 'Plant head', 'min_amount' => '500000', 'approver_user_id' => $approver->id])->assertSessionHasNoErrors();
        $this->post(route('buyer.approval-rules.store'), ['name' => 'Director', 'min_amount' => '2500000'])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $rules = ApprovalRule::withoutGlobalScopes()->orderBy('position')->get();
        $this->assertSame(['Plant head', 'Director'], $rules->pluck('name')->all());

        // Move Director up.
        $d = $rules[1];
        $this->actingAs($this->buyerUser)->put(route('buyer.approval-rules.update', $d->id), ["rule{$d->id}_name" => 'Director', "rule{$d->id}_min_amount" => '2500000', 'move' => 'up'])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->assertSame(['Director', 'Plant head'], ApprovalRule::withoutGlobalScopes()->orderBy('position')->pluck('name')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval_rule_changed']);

        $this->actingAs($approver)->get(route('buyer.approval-rules.index'))->assertOk()->assertSee('Plant head')->assertDontSee('Add a level');
        $this->actingAs($approver)->post(route('buyer.approval-rules.store'), ['name' => 'Sneaky', 'min_amount' => '0'])->assertForbidden();
        $this->actingAs($plain)->delete(route('buyer.approval-rules.destroy', $d->id))->assertForbidden();
        // Another company can't touch them.
        [$other, $otherAdmin] = $this->buyer('Other Buyer');
        $this->actingAs($otherAdmin)->delete(route('buyer.approval-rules.destroy', $d->id))->assertNotFound();
        $this->assertSame(2, ApprovalRule::withoutGlobalScopes()->count());
    }

    public function test_never_stuck_with_too_few_people_and_named_levels_stay_with_their_person(): void
    {
        // One approver only, two "any approver" levels: the second can't be staffed and is left out (and recorded).
        $only = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($this->buyerUser);
        $this->post(route('buyer.approval-rules.store'), ['name' => 'Manager', 'min_amount' => '0'])->assertSessionHasNoErrors();
        $this->post(route('buyer.approval-rules.store'), ['name' => 'Second check', 'min_amount' => '0'])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $this->threeQuotesAndDeadline();
        $this->awardTo('B')->assertSessionHasNoErrors();
        $a = $this->award();
        $this->assertSame(['Manager'], AwardApprovalStep::withoutGlobalScopes()->pluck('name')->all());
        $this->assertTrue(AuditLog::where('action', 'awarded')->get()->contains(fn ($l) => str_contains(json_encode($l->after), 'Second check')));
        $this->actingAs($only)->post(route('buyer.awards.approve', $a->id))->assertRedirect();
        $this->assertSame(AwardStatus::PoSent, $a->fresh()->status);
    }

    public function test_named_level_is_not_taken_by_someone_else_and_admins_can_always_turn_an_award_down(): void
    {
        $this->rules(); // Manager (named), Director (named, from 93,000 or not L1)
        $junior = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->threeQuotesAndDeadline();
        $this->awardTo('A', ['reason' => 'Better delivery record last quarter']);
        $a = $this->award();
        $this->actingAs($this->manager)->post(route('buyer.awards.approve', $a->id))->assertRedirect();
        // The Director level belongs to the Director: the junior approver can't approve it.
        $this->actingAs($junior)->post(route('buyer.awards.approve', $a->id))->assertForbidden();

        // A stale click (made while looking at level 1) is refused rather than applied to level 2.
        $level1 = AwardApprovalStep::withoutGlobalScopes()->where('position', 1)->value('id');
        $this->actingAs($this->director)->post(route('buyer.awards.approve', $a->id), ['step_id' => $level1])->assertSessionHasErrors('decision_note');
        $this->assertSame(AwardStatus::PendingApproval, $a->fresh()->status);

        // The company owner (an admin, not on this level) can still reject it, so nothing stays stuck.
        $owner = $this->memberOf($this->buyer, OrgRole::BuyerAdmin);
        $this->actingAs($owner)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Reject award');
        $this->actingAs($owner)->post(route('buyer.awards.approve', $a->id))->assertForbidden();
        $this->actingAs($owner)->post(route('buyer.awards.reject', $a->id), ['decision_note' => 'Project put on hold'])->assertRedirect();
        $this->assertSame(AwardStatus::Rejected, $a->fresh()->status);
    }
}
