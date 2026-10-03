<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Mail\RfqClarificationMail;
use App\Mail\RfqQuestionMail;
use App\Models\AuditLog;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\RfqQuestion;
use App\Models\User;
use App\Services\RfqQuestionService;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** Supplier questions and buyer clarifications while quotes are open. */
class RfqQuestionTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User}> */
    private array $s = [];
    private Rfq $rfq;
    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->t0 = Carbon::parse('2026-10-05 04:30:00', 'UTC');
        $this->travelTo($this->t0);

        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => 'Alpha Packaging', 'B' => 'Beta Corrugators', 'C' => 'Gamma Boxes'] as $k => $name) {
            [$org, $user] = $this->supplier($name);
            BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user];
        }
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Corrugated boxes',
            'quote_deadline' => $this->t0->copy()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box 5-ply', 'qty' => 1000, 'unit' => 'pcs']],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);
        $this->rfq = $rfq->fresh();
        Mail::fake(); // forget the invitations
    }

    private function invite(string $k): RfqInvite
    {
        return RfqInvite::where('rfq_id', $this->rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
    }

    private function ask(string $k, string $text): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.questions.store', $this->invite($k)->id), ['question' => $text]);
    }

    public function test_supplier_asks_buyer_is_emailed_and_sees_who_asked(): void
    {
        $this->ask('A', 'Is printing on one side or two?')->assertRedirect()->assertSessionHasNoErrors();
        $q = RfqQuestion::firstOrFail();
        $this->assertSame($this->s['A'][0]->id, $q->supplier_org_id);
        $this->assertSame($this->buyer->id, $q->organization_id);
        Mail::assertQueued(RfqQuestionMail::class, fn ($m) => $m->hasTo($this->buyerUser->email));
        $this->assertTrue(AuditLog::where('action', 'rfq_question_asked')->exists());

        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertOk()
            ->assertSee('1 waiting')->assertSee('Alpha Packaging')->assertSee('Is printing on one side or two?')
            ->assertSee('Alpha Packaging asked a question');

        // The asker sees it as pending; other suppliers don't see an unanswered question at all.
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.show', $this->invite('A')->id))->assertOk()
            ->assertSee('Your question')->assertSee('Waiting for the buyer');
        $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $this->invite('B')->id))->assertOk()
            ->assertDontSee('Is printing on one side or two?');
    }

    public function test_answer_to_everyone_never_reveals_the_asker(): void
    {
        $this->ask('A', 'Is printing on one side or two?');
        $q = RfqQuestion::firstOrFail();

        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'Two sides, two colours.', 'visibility' => 'all'])
            ->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertQueued(RfqClarificationMail::class, 3);
        Mail::assertQueued(RfqClarificationMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email) && $m->mine);
        Mail::assertQueued(RfqClarificationMail::class, fn ($m) => $m->hasTo($this->s['B'][1]->email) && ! $m->mine);

        $page = $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $this->invite('B')->id))->assertOk()
            ->assertSee('A supplier asked')->assertSee('Is printing on one side or two?')->assertSee('Two sides, two colours.');
        $page->assertDontSee('Alpha Packaging');

        // Both emails render; the supplier email never names who asked.
        $this->assertStringContainsString('Alpha Packaging', (new RfqQuestionMail($q->fresh()))->render());
        $html = (new RfqClarificationMail($this->invite('B'), $q->fresh()))->render();
        $this->assertStringContainsString('Two sides, two colours.', $html);
        $this->assertStringNotContainsString('Alpha Packaging', $html);

        // Answers are final.
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'Changed my mind', 'visibility' => 'all'])
            ->assertSessionHasErrors('answer');
        $this->assertSame('Two sides, two colours.', $q->fresh()->answer);
    }

    public function test_private_answer_goes_only_to_the_asker(): void
    {
        $this->ask('A', 'Can you share your GST number for our records?');
        $q = RfqQuestion::firstOrFail();
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'It is on the PO.', 'visibility' => 'private']);

        Mail::assertQueued(RfqClarificationMail::class, 1);
        Mail::assertQueued(RfqClarificationMail::class, fn ($m) => $m->hasTo($this->s['A'][1]->email));
        $this->actingAs($this->s['A'][1])->get(route('supplier.rfqs.show', $this->invite('A')->id))->assertSee('It is on the PO.')->assertSee('only to you');
        $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $this->invite('B')->id))->assertDontSee('It is on the PO.')->assertDontSee('GST number');
    }

    public function test_buyer_clarification_reaches_everyone_still_in_the_rfq(): void
    {
        $this->invite('C')->update(['status' => 'declined']);
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.clarifications.store', $this->rfq->id), ['text' => 'Drawing v2 is attached; please quote to it.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertQueued(RfqClarificationMail::class, 2); // A and B, not C who declined
        $this->actingAs($this->s['B'][1])->get(route('supplier.rfqs.show', $this->invite('B')->id))->assertSee('Clarification from the buyer')->assertSee('Drawing v2 is attached');
        $this->assertTrue(AuditLog::where('action', 'rfq_clarification_posted')->exists());
    }

    public function test_rules_and_isolation(): void
    {
        // Too short, and a supplier can't reach another supplier's invite.
        $this->ask('A', 'Hi')->assertSessionHasErrors('question');
        $this->actingAs($this->s['B'][1])->post(route('supplier.rfqs.questions.store', $this->invite('A')->id), ['question' => 'Sneaky question here'])->assertNotFound();

        // A declined supplier can't ask.
        $this->invite('C')->update(['status' => 'declined']);
        $this->ask('C', 'Can I still ask something?')->assertSessionHasErrors('question');

        // Approvers can't answer (buyer staff only), and another buyer can't reach the RFQ.
        $this->ask('A', 'Is printing on one side or two?');
        $q = RfqQuestion::firstOrFail();
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($approver)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'Two sides.', 'visibility' => 'all'])->assertForbidden();
        [, $otherUser] = $this->buyer('Other Buyer');
        $this->actingAs($otherUser)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'Two sides.', 'visibility' => 'all'])->assertNotFound();

        // After the deadline: no new questions or answers.
        $this->travelTo($this->t0->copy()->addHours(4));
        $this->ask('B', 'Late question about delivery?')->assertSessionHasErrors('question');
        $this->actingAs($this->buyerUser)->post(route('buyer.rfqs.questions.answer', [$this->rfq->id, $q->id]), ['answer' => 'Two sides.', 'visibility' => 'all'])
            ->assertSessionHasErrors('answer');
        $this->actingAs($this->buyerUser)->get(route('buyer.rfqs.show', $this->rfq->id))->assertSee('Not answered before quotes closed');
    }

    public function test_question_limit_per_supplier(): void
    {
        for ($i = 1; $i <= RfqQuestionService::MAX_PER_SUPPLIER; $i++) {
            RfqQuestion::create(['rfq_id' => $this->rfq->id, 'organization_id' => $this->buyer->id, 'supplier_org_id' => $this->s['A'][0]->id, 'question' => "Question number {$i}"]);
        }
        $this->ask('A', 'One question too many?')->assertSessionHasErrors('question');
        $this->ask('B', 'A different supplier can still ask?')->assertSessionHasNoErrors();
    }
}
