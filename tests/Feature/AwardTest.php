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

class AwardTest extends TestCase
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
        $this->diskRoot = sys_get_temp_dir().'/getl1-award-'.bin2hex(random_bytes(6));
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

    // ---------------------------------------------------------------- award and PO

    public function test_award_to_l1_issues_the_purchase_order_automatically(): void
    {
        $this->threeQuotesAndDeadline();
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()
            ->assertSee('Award this RFQ')->assertSee('Beta Corrugators');

        $this->awardTo('B')->assertRedirect(route('buyer.rfqs.show', $this->rfq->id).'#award');

        $a = $this->award();
        $this->assertSame(AwardStatus::PoSent, $a->status);
        $this->assertSame('PO-2026-0001', $a->po_number);
        $this->assertSame(1, $a->rank);
        $this->assertEquals(91000, (float) $a->total);
        $this->assertEquals(16380, (float) $a->gst_total);
        $this->assertEquals(107380, (float) $a->grand_total);
        $this->assertSame(RfqStatus::Awarded, $this->rfq->fresh()->status);

        $pdf = Storage::disk('local')->get($a->po_pdf_path);
        $this->assertStringStartsWith('%PDF', $pdf);

        Mail::assertQueued(PurchaseOrderMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email));
        Mail::assertQueued(NotSelectedMail::class, 2);
        Mail::assertNotQueued(NotSelectedMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email));
        foreach (['awarded', 'award_approved', 'po_issued'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
        }

        // Buyer can download the PO; the page shows it.
        $this->actingAs($this->buyerUser)->get(route('buyer.awards.po', $a->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()
            ->assertSee('PO-2026-0001')->assertSee('Download PO (PDF)')->assertSee('Purchase order PO-2026-0001 issued');

        // Emails render with the PDF attached.
        $mail = Mail::queued(PurchaseOrderMail::class)->first();
        $this->assertStringContainsString('PO-2026-0001', $mail->render());
        $this->assertCount(1, $mail->attachments());
        $this->assertStringNotContainsString('Beta', Mail::queued(NotSelectedMail::class)->first()->render());
    }

    public function test_not_l1_needs_a_reason(): void
    {
        $this->threeQuotesAndDeadline();
        $this->awardTo('A')->assertSessionHasErrors('reason');
        $this->assertSame(0, Award::withoutGlobalScopes()->count());

        $this->awardTo('A', ['reason' => 'Beta failed quality inspection in August'])->assertSessionHasNoErrors();
        $a = $this->award();
        $this->assertSame(2, $a->rank);
        $this->assertSame('Beta failed quality inspection in August', $a->reason);
    }

    public function test_one_award_per_rfq_and_none_while_quotes_are_sealed(): void
    {
        $this->quote('A', [90, 20]);
        $this->quote('B', [85, 30]);
        $this->awardTo('B')->assertSessionHasErrors('award');

        $this->travelTo($this->t0->copy()->addHours(4));
        $this->awardTo('B')->assertSessionHasNoErrors();
        $this->awardTo('A', ['reason' => 'Trying to award twice here'])->assertSessionHasErrors('award');
        $this->assertSame(1, Award::withoutGlobalScopes()->count());
    }

    public function test_no_award_while_an_auction_is_scheduled(): void
    {
        $this->threeQuotesAndDeadline();
        app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(30)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5',
            'max_decrement_pct' => '10', 'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
        $this->awardTo('B')->assertSessionHasErrors('award');
    }

    public function test_award_after_auction_uses_final_price_and_lines_add_up(): void
    {
        $this->threeQuotesAndDeadline();
        $auction = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5',
            'max_decrement_pct' => '10', 'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
        $this->travelTo($auction->starts_at->copy()->addSecond());
        $this->actingAs($this->s['A'][1])->postJson(route('supplier.auctions.bid', $auction->id), ['amount' => '87777', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->travelTo($auction->ends_at->copy()->addSecond());
        app(CurrentOrganization::class)->set(null);

        $this->awardTo('A')->assertSessionHasNoErrors();
        $a = $this->award();
        $this->assertSame('auction', $a->source);
        $this->assertSame(1, $a->rank);
        $this->assertEquals(87777, (float) $a->total);

        $sum = collect($a->lines['items'])->sum('amount') + $a->lines['round_off'];
        $this->assertEqualsWithDelta(87777, $sum, 0.001, 'PO lines + round-off equal the auction price');
        $this->assertLessThan(1, abs($a->lines['round_off']));
    }

    // ---------------------------------------------------------------- approval

    public function test_approval_flow_and_no_self_approval(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->threeQuotesAndDeadline();

        $this->awardTo('B');
        $a = $this->award();
        $this->assertSame(AwardStatus::PendingApproval, $a->status);
        $this->assertSame(RfqStatus::Evaluating, $this->rfq->fresh()->status);
        Mail::assertQueued(AwardApprovalRequestMail::class, fn ($m) => $m->hasTo($approver->email));
        Mail::assertNotQueued(PurchaseOrderMail::class);

        // The admin who awarded can't approve their own award.
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.approve', $a->id))->assertForbidden();
        // A plain buyer user can't reach approvals at all.
        $plain = $this->memberOf($this->buyer, OrgRole::BuyerUser);
        $this->actingAs($plain)->post(route('buyer.awards.approve', $a->id))->assertForbidden();

        $this->actingAs($approver)->get(route('buyer.approvals.index'))->assertOk()->assertSee('Beta Corrugators')->assertSee('Review');
        $this->actingAs($approver)->post(route('buyer.awards.approve', $a->id), ['decision_note' => 'Fine'])->assertRedirect();

        $a->refresh();
        $this->assertSame(AwardStatus::PoSent, $a->status);
        $this->assertSame($approver->id, $a->approved_by);
        Mail::assertQueued(AwardDecisionMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        Mail::assertQueued(PurchaseOrderMail::class);

        $this->assertContains('Award waiting for your approval', $approver->fresh()->notifications()->get()->pluck('data.title')->all());
        $this->assertContains('Award approved: '.$this->rfq->ref_no, $this->buyerUser->fresh()->notifications()->get()->pluck('data.title')->all());
    }

    public function test_rejected_award_can_be_redone(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->threeQuotesAndDeadline();
        $this->awardTo('B');
        $a = $this->award();

        $this->actingAs($approver)->post(route('buyer.awards.reject', $a->id), ['decision_note' => ''])->assertSessionHasErrors('decision_note');
        $this->actingAs($approver)->post(route('buyer.awards.reject', $a->id), ['decision_note' => 'Check delivery terms first'])->assertRedirect();
        $this->assertSame(AwardStatus::Rejected, $a->fresh()->status);
        $this->assertSame(RfqStatus::Evaluating, $this->rfq->fresh()->status);

        $this->awardTo('B')->assertSessionHasNoErrors();
        $this->assertSame(2, Award::withoutGlobalScopes()->count());
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Earlier rejected awards');
    }

    public function test_awards_below_the_limit_skip_approval(): void
    {
        $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($this->buyerUser)->put(route('company.update'), [
            'name' => 'Acme Buyers', 'city' => 'Panchkula', 'award_approval_limit' => '200000', 'po_terms' => "Deliver to gate 2.\nInspection on receipt.",
        ])->assertSessionHasNoErrors();
        $this->assertEquals(200000, (float) $this->buyer->fresh()->award_approval_limit);

        $this->threeQuotesAndDeadline();
        $this->awardTo('B');
        $this->assertSame(AwardStatus::PoSent, $this->award()->status);
    }

    // ---------------------------------------------------------------- supplier orders

    public function test_supplier_sees_accepts_and_downloads_only_own_orders(): void
    {
        $this->threeQuotesAndDeadline();
        $this->awardTo('B');
        $a = $this->award();

        $this->actingAs($this->s['B'][1])->get(route('supplier.orders.index'))->assertOk()->assertSee('PO-2026-0001')->assertSee('Accept now');
        $inviteB = RfqInvite::where('rfq_id', $this->rfq->id)->where('supplier_org_id', $this->s['B'][0]->id)->firstOrFail();
        $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $inviteB->id))->assertOk()->assertSee('You won this order');
        $this->actingAs($this->s['B'][1])->get(route('supplier.orders.po', $a->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->s['B'][1])->post(route('supplier.orders.accept', $a->id))->assertRedirect();
        $this->assertNotNull($a->fresh()->supplier_accepted_at);
        Mail::assertQueued(PoAcceptedMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));

        // Accepting twice changes nothing.
        $this->actingAs($this->s['B'][1])->post(route('supplier.orders.accept', $a->id));
        Mail::assertQueued(PoAcceptedMail::class, 1);

        // Other suppliers and other buyers: 404.
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.show', $a->id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.po', $a->id))->assertNotFound();
        $this->actingAs($this->s['A'][1])->get(route('supplier.orders.index'))->assertOk()->assertDontSee('PO-2026-0001');
        [, $rival] = $this->buyer('Rival Industries');
        $this->actingAs($rival)->get(route('buyer.awards.po', $a->id))->assertNotFound();
    }

    public function test_po_numbers_are_sequential_per_company(): void
    {
        $this->threeQuotesAndDeadline();
        $this->awardTo('B');

        $this->travelTo($this->t0->copy()->addDay());
        $second = $this->publishedRfq($this->buyer, $this->buyerUser);
        $this->rfq = $second;
        $this->quote('A', [90, 20], $second);
        $this->travelTo(now()->addHours(4));
        $this->awardTo('A');
        $this->assertSame('PO-2026-0002', $this->award()->po_number);

        // Another company starts at 0001.
        [$otherBuyer, $otherUser] = $this->buyer('Rival Industries');
        BuyerSupplier::create(['buyer_org_id' => $otherBuyer->id, 'company_name' => 'Alpha Packaging', 'status' => 'active',
            'contact_email' => $this->s['A'][1]->email, 'supplier_org_id' => $this->s['A'][0]->id]);
        $third = $this->publishedRfq($otherBuyer, $otherUser);
        $this->rfq = $third;
        $this->quote('A', [90, 20], $third);
        $this->travelTo(now()->addHours(4));
        $this->awardTo('A', [], $otherUser);
        $this->assertSame('PO-2026-0001', $this->award()->po_number);
    }

    // ---------------------------------------------------------------- audit views

    public function test_activity_shows_every_step_without_leaking_sealed_prices(): void
    {
        $this->quote('A', [90, 20]);
        $page = $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk();
        $page->assertSee('Alpha Packaging submitted a sealed quote')->assertSee('Published the RFQ');
        $this->assertStringNotContainsString('94,000', $page->getContent(), 'Sealed amount must not appear');
    }

    public function test_bid_log_csv_is_complete_and_formula_safe(): void
    {
        $this->threeQuotesAndDeadline();
        $this->s['A'][1]->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();
        $auction = app(AuctionService::class)->schedule($this->rfq->fresh(), $this->buyerUser, [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5',
            'max_decrement_pct' => '10', 'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
        $this->travelTo($auction->starts_at->copy()->addSecond());
        $this->actingAs($this->s['A'][1])->postJson(route('supplier.auctions.bid', $auction->id), ['amount' => '88000', 'idempotency_key' => (string) Str::uuid()])->assertOk();

        $csv = $this->actingAs($this->buyerUser)->get(route('buyer.auctions.bids', $auction->id))->assertOk()->streamedContent();
        $this->assertStringContainsString('Time (IST)', $csv);
        $this->assertSame(1 + 4, substr_count(trim($csv), "\n") + 1, 'header + 3 sealed + 1 live');
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
        $this->assertStringContainsString('88000.00', $csv);

        [, $rival] = $this->buyer('Rival Industries');
        $this->actingAs($rival)->get(route('buyer.auctions.bids', $auction->id))->assertNotFound();
    }

    public function test_amount_in_words(): void
    {
        $this->assertSame('Rupees One Lakh Seven Thousand Three Hundred Eighty Only', Money::words(107380));
        $this->assertSame('Rupees One Crore Twenty Lakh and Five Paise Only', Money::words(12000000.05));
        $this->assertSame('Rupees Zero Only', Money::words(0));
    }
}
