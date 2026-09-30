<?php

namespace Tests\Feature;

use App\Enums\InviteStatus;
use App\Mail\RfqInvitationMail;
use App\Mail\RfqUpdateMail;
use App\Models\AuditLog;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;
use Tests\Unit\SpreadsheetReaderTest;

class RfqFlowTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-test-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    /** Requests set the current org; clear it so direct model queries in tests aren't scoped. */
    private function unscope(): void
    {
        app(CurrentOrganization::class)->set(null);
    }

    private function listEntry(string $company, array $contact): BuyerSupplier
    {
        return BuyerSupplier::create(array_merge(['buyer_org_id' => $this->buyer->id, 'company_name' => $company, 'status' => 'active'], $contact));
    }

    /** Supplier company + user + an entry in the buyer's list pointing at them (linked). */
    private function linkedSupplier(string $name): array
    {
        [$org, $user] = $this->supplier($name);
        $entry = $this->listEntry($name, ['contact_email' => $user->email, 'supplier_org_id' => $org->id]);

        return [$org, $user, $entry];
    }

    private function deadlineIst(int $hours = 3): string
    {
        return now()->addHours($hours)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i');
    }

    private function draft(array $overrides = []): Rfq
    {
        return app(RfqService::class)->saveDraft($this->buyer, $this->buyerUser, array_merge([
            'title' => 'Corrugated boxes',
            'quote_deadline' => $this->deadlineIst(),
            'items' => [
                ['name' => 'Box 5-ply', 'qty' => 10000, 'unit' => 'pcs', 'last_purchase_price' => 99999.99],
                ['name' => 'BOPP tape', 'qty' => 200, 'unit' => 'roll'],
            ],
        ], $overrides));
    }

    private function published(array $entries): Rfq
    {
        $rfq = $this->draft();
        $svc = app(RfqService::class);
        $svc->invite($rfq, $this->buyerUser, collect($entries)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);

        return $rfq->fresh();
    }

    private function inviteFor(Rfq $rfq, Organization $supplier): RfqInvite
    {
        return RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $supplier->id)->firstOrFail();
    }

    private function quotePayload(Rfq $rfq, array $prices): array
    {
        $items = [];
        foreach ($rfq->items()->get() as $i => $item) {
            $items[$item->id] = ['unit_price' => $prices[$i], 'gst_rate' => '18', 'freight' => 0];
        }

        return ['items' => $items, 'valid_till' => now()->addDays(10)->format('Y-m-d'), 'total' => 1];
    }

    // ---------------------------------------------------------------- buyer

    public function test_buyer_creates_a_draft_over_http(): void
    {
        $this->actingAs($this->buyerUser)->post('/buyer/rfqs', [
            'title' => 'Steel rods',
            'quote_deadline' => $this->deadlineIst(),
            'items' => [5 => ['name' => 'TMT 12mm', 'qty' => '2.5', 'unit' => 'mt']],
        ])->assertRedirect();

        $this->unscope();
        $rfq = Rfq::with('items')->firstOrFail();
        $this->assertTrue($rfq->isDraft());
        $this->assertSame(1, $rfq->items->first()->line_no);
        $this->assertTrue(AuditLog::where('action', 'rfq_created')->exists());
    }

    public function test_publish_needs_suppliers_and_a_future_deadline(): void
    {
        $rfq = $this->draft(['quote_deadline' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i')]);

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/publish")
            ->assertSessionHasErrors(['quote_deadline', 'suppliers']);
        $this->unscope();
        $this->assertTrue($rfq->fresh()->isDraft());
    }

    public function test_publishing_emails_every_invited_supplier(): void
    {
        [, , $a] = $this->linkedSupplier('Sharma Cartons');
        [, , $b] = $this->linkedSupplier('Punjab Box Works');

        $rfq = $this->published([$a, $b]);

        Mail::assertQueued(RfqInvitationMail::class, 2);
        $this->assertTrue($rfq->isOpenForQuotes());
        $this->assertTrue(AuditLog::where('action', 'rfq_published')->exists());
    }

    public function test_blocked_and_foreign_suppliers_cannot_be_invited(): void
    {
        $blocked = $this->listEntry('Blocked Co', ['contact_phone' => '9876500001', 'status' => 'blocked']);
        [$otherBuyer] = $this->buyer('Other Buyer');
        $foreign = BuyerSupplier::create(['buyer_org_id' => $otherBuyer->id, 'company_name' => 'Theirs', 'contact_phone' => '9876500002']);
        $rfq = $this->draft();

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/invites", ['suppliers' => [$foreign->id]])
            ->assertSessionHasErrors('suppliers.0');
        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/invites", ['suppliers' => [$blocked->id]]);

        $this->assertSame(0, RfqInvite::count());
    }

    public function test_other_buyers_cannot_see_or_touch_the_rfq(): void
    {
        $rfq = $this->draft();
        [, $otherUser] = $this->buyer('Other Buyer');

        $this->actingAs($otherUser)->get("/buyer/rfqs/{$rfq->id}")->assertNotFound();
        $this->actingAs($otherUser)->get("/buyer/rfqs/{$rfq->id}/edit")->assertNotFound();
        $this->actingAs($otherUser)->post("/buyer/rfqs/{$rfq->id}/publish")->assertNotFound();
        $this->actingAs($otherUser)->post("/buyer/rfqs/{$rfq->id}/cancel", ['reason' => 'sabotage'])->assertNotFound();
    }

    // ---------------------------------------------------------------- sealed quotes

    public function test_quotes_stay_sealed_until_the_deadline_then_are_ranked(): void
    {
        [$sA, $uA, $eA] = $this->linkedSupplier('Sharma Cartons');
        [$sB, $uB, $eB] = $this->linkedSupplier('Punjab Box Works');
        $rfq = $this->published([$eA, $eB]);

        foreach ([[$sA, $uA, [13.37, 55]], [$sB, $uB, [12.94, 50]]] as [$org, $user, $prices]) {
            $inv = $this->inviteFor($rfq, $org);
            $this->actingAs($user)->post("/supplier/rfqs/{$inv->id}/accept", ['agree' => 1]);
            $this->actingAs($user)->post("/supplier/rfqs/{$inv->id}/quote", $this->quotePayload($rfq, $prices))->assertSessionHasNoErrors();
        }

        // Before the deadline: count only, no prices anywhere on the page.
        $this->actingAs($this->buyerUser)->get("/buyer/rfqs/{$rfq->id}")->assertOk()
            ->assertSee('2 of 2 suppliers have quoted')
            ->assertDontSee('13.37')->assertDontSee('1,33,700')->assertDontSee('12.94')->assertDontSee('Quote comparison');

        $this->expectsSealedException($rfq);

        // After the deadline: ranked comparison, L1 = lowest landed cost.
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->get("/buyer/rfqs/{$rfq->id}")->assertOk()
            ->assertSee('Quote comparison')
            ->assertSeeInOrder(['L1', 'Punjab Box Works', 'L2', 'Sharma Cartons']);
    }

    private function expectsSealedException(Rfq $rfq): void
    {
        try {
            app(RfqService::class)->comparison($rfq->fresh());
            $this->fail('comparison() must refuse while quotes are sealed');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_quote_total_is_computed_on_the_server(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $inv = $this->inviteFor($rfq, $s);
        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/accept", ['agree' => 1]);

        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/quote", $this->quotePayload($rfq, [12.60, 40]));

        $this->unscope();
        // 10000 × 12.60 + 200 × 40 = 134000 (the posted "total" of 1 is ignored)
        $this->assertSame('134000.00', Quote::firstOrFail()->total);
    }

    public function test_quote_must_cover_every_item_and_needs_acceptance(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $inv = $this->inviteFor($rfq, $s);

        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/quote", $this->quotePayload($rfq, [10, 10]))
            ->assertSessionHasErrors('items');

        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/accept", ['agree' => 1]);
        $payload = $this->quotePayload($rfq, [10, 10]);
        array_pop($payload['items']);
        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/quote", $payload)->assertSessionHasErrors('items');

        $this->assertSame(0, Quote::count());
    }

    public function test_no_quotes_after_the_deadline(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $inv = $this->inviteFor($rfq, $s);
        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/accept", ['agree' => 1]);

        $this->travel(4)->hours();
        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/quote", $this->quotePayload($rfq, [10, 10]))
            ->assertSessionHasErrors('rfq');
        $this->assertSame(0, Quote::count());
    }

    public function test_declined_supplier_cannot_quote(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $inv = $this->inviteFor($rfq, $s);

        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/decline", ['reason' => 'No stock']);
        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/quote", $this->quotePayload($rfq, [10, 10]))
            ->assertSessionHasErrors('items');

        $this->assertSame(InviteStatus::Declined, $inv->fresh()->status);
    }

    // ---------------------------------------------------------------- supplier privacy

    public function test_supplier_never_sees_last_price_or_other_quotes(): void
    {
        [$sA, $uA, $eA] = $this->linkedSupplier('Sharma Cartons');
        [$sB, $uB, $eB] = $this->linkedSupplier('Punjab Box Works');
        $rfq = $this->published([$eA, $eB]);

        $invA = $this->inviteFor($rfq, $sA);
        $this->actingAs($uA)->post("/supplier/rfqs/{$invA->id}/accept", ['agree' => 1]);
        $this->actingAs($uA)->post("/supplier/rfqs/{$invA->id}/quote", $this->quotePayload($rfq, [13.37, 55]));

        $invB = $this->inviteFor($rfq, $sB);
        $this->actingAs($uB)->get("/supplier/rfqs/{$invB->id}")->assertOk()
            ->assertDontSee('99,999.99')->assertDontSee('99999.99')   // buyer's last purchase price
            ->assertDontSee('13.37')->assertDontSee('Sharma Cartons'); // competitor's price and name

        // Supplier B can't open A's invite, and a supplier without an invite sees nothing.
        $this->actingAs($uB)->get("/supplier/rfqs/{$invA->id}")->assertNotFound();
        [, $stranger] = $this->supplier('Stranger Co');
        $this->actingAs($stranger)->get("/supplier/rfqs/{$invA->id}")->assertNotFound();
    }

    public function test_draft_rfqs_are_invisible_to_suppliers(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->draft();
        app(RfqService::class)->invite($rfq, $this->buyerUser, [$e->id]);
        $inv = $this->inviteFor($rfq, $s);

        $this->actingAs($u)->get("/supplier/rfqs/{$inv->id}")->assertNotFound();
        $this->actingAs($u)->get('/dashboard')->assertDontSee('Corrugated boxes');
        $this->get('/i/'.$inv->token)->assertNotFound();
    }

    // ---------------------------------------------------------------- invite links

    public function test_invite_link_binds_to_the_matching_supplier(): void
    {
        [$org, $user] = $this->supplier('Sharma Cartons');
        $entry = $this->listEntry('Sharma Cartons', ['contact_email' => $user->email]); // not linked yet
        $rfq = $this->published([$entry]);
        $invite = RfqInvite::where('rfq_id', $rfq->id)->firstOrFail();
        $this->assertNull($invite->supplier_org_id);

        $this->get('/i/'.$invite->token)->assertOk()->assertSee('Sign in');   // guest summary

        $this->actingAs($user)->get('/i/'.$invite->token)->assertRedirect(route('supplier.rfqs.show', $invite->id));
        $this->assertSame($org->id, $invite->fresh()->supplier_org_id);
        $this->assertSame($org->id, $entry->fresh()->supplier_org_id);
    }

    public function test_forwarded_invite_link_cannot_be_claimed_by_someone_else(): void
    {
        [, $intended] = $this->supplier('Sharma Cartons');
        $entry = $this->listEntry('Sharma Cartons', ['contact_email' => $intended->email]);
        $rfq = $this->published([$entry]);
        $invite = RfqInvite::where('rfq_id', $rfq->id)->firstOrFail();

        [, $other] = $this->supplier('Rival Packaging');
        $this->actingAs($other)->get('/i/'.$invite->token)->assertOk()->assertSee('sent to a different contact');

        $this->assertNull($invite->fresh()->supplier_org_id);
    }

    public function test_buyers_cannot_use_supplier_invite_links(): void
    {
        [, , $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $invite = RfqInvite::where('rfq_id', $rfq->id)->firstOrFail();
        [, $otherBuyer] = $this->buyer('Nosy Buyer');

        $this->actingAs($otherBuyer)->get('/i/'.$invite->token)->assertOk()->assertSee('for suppliers');
    }

    public function test_unknown_tokens_are_404(): void
    {
        $this->get('/i/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/i/<script>')->assertNotFound();
    }

    // ---------------------------------------------------------------- cancel, extend, emails, attachments

    public function test_cancel_notifies_suppliers_and_blocks_quotes(): void
    {
        [$s, $u, $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);
        $inv = $this->inviteFor($rfq, $s);

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/cancel", ['reason' => 'Requirement changed'])->assertRedirect();
        Mail::assertQueued(RfqUpdateMail::class, fn ($m) => $m->kind === 'cancelled');

        $this->actingAs($u)->post("/supplier/rfqs/{$inv->id}/accept", ['agree' => 1])->assertSessionHasErrors('rfq');
    }

    public function test_deadline_can_only_move_later(): void
    {
        [, , $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->published([$e]);

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/extend", ['quote_deadline' => $this->deadlineIst(1)])
            ->assertSessionHasErrors('quote_deadline');
        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/extend", ['quote_deadline' => $this->deadlineIst(24)])
            ->assertSessionHasNoErrors();

        Mail::assertQueued(RfqUpdateMail::class, fn ($m) => $m->kind === 'extended');
    }

    public function test_invitation_email_cannot_carry_injected_links(): void
    {
        [$s, , $e] = $this->linkedSupplier('Sharma Cartons');
        $rfq = $this->draft(['title' => '[Pay advance here](http://evil.example)']);
        app(RfqService::class)->invite($rfq, $this->buyerUser, [$e->id]);
        $this->unscope();

        $html = (new RfqInvitationMail($this->inviteFor($rfq, $s)))->render();

        $this->assertStringNotContainsString('href="http://evil.example"', $html);
        $this->assertStringContainsString('/i/', $html);
    }

    public function test_attachments_are_checked_and_private(): void
    {
        $rfq = $this->draft();
        $xlsx = SpreadsheetReaderTest::makeXlsx([['Spec'], ['5-ply']]);

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/attachments", [
            'file' => new UploadedFile($xlsx, 'drawing.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->buyerUser)->post("/buyer/rfqs/{$rfq->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('spec.pdf', '<?php echo 1;'),
        ])->assertSessionHasErrors('file');

        $this->unscope();
        $att = $rfq->attachments()->firstOrFail();
        [, $otherBuyer] = $this->buyer('Other Buyer');
        $this->actingAs($otherBuyer)->get("/buyer/rfqs/{$rfq->id}/attachments/{$att->id}")->assertNotFound();
        $this->actingAs($this->buyerUser)->get("/buyer/rfqs/{$rfq->id}/attachments/{$att->id}")->assertOk()->assertDownload('drawing.xlsx');
        @unlink($xlsx);
    }

    public function test_approvers_can_view_but_not_change_rfqs(): void
    {
        $approver = $this->memberOf($this->buyer, \App\Enums\OrgRole::Approver);
        $rfq = $this->draft();

        $this->actingAs($approver)->get("/buyer/rfqs/{$rfq->id}")->assertOk();
        $this->actingAs($approver)->post("/buyer/rfqs/{$rfq->id}/publish")->assertForbidden();
        $this->actingAs($approver)->get('/buyer/rfqs/create')->assertForbidden();
    }
}
