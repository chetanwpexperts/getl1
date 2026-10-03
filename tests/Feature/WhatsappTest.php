<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Models\Award;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\RfqService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** WhatsApp alerts: ready to plug in, off until configured, never in the way. */
class WhatsappTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $buyerUser;
    /** @var array<string, array{0: Organization, 1: User, 2: BuyerSupplier}> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->buyerUser] = $this->buyer('Acme Buyers');
        foreach (['A' => ['Alpha Packaging', '9876500001'], 'B' => ['Beta Corrugators', '98765 00002']] as $k => [$name, $phone]) {
            [$org, $user] = $this->supplier($name);
            $entry = BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => $name, 'status' => 'active',
                'contact_email' => $user->email, 'contact_phone' => preg_replace('/\D/', '', $phone), 'supplier_org_id' => $org->id]);
            $this->s[$k] = [$org, $user, $entry];
        }
        // Not on GetL1 yet: phone only.
        BuyerSupplier::create(['buyer_org_id' => $this->buyer->id, 'company_name' => 'Gamma Boxes', 'status' => 'active', 'contact_phone' => '9876500003']);
    }

    private function enable(): void
    {
        config(['whatsapp.phone_number_id' => '1234567890', 'whatsapp.token' => 'test-token', 'whatsapp.app_secret' => 'shh', 'whatsapp.verify_token' => 'verify-me']);
        Http::fake(['graph.facebook.com/*' => Http::sequence()->whenEmpty(Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200))]);
    }

    private function rfq(): Rfq
    {
        $svc = app(RfqService::class);
        $rfq = $svc->saveDraft($this->buyer, $this->buyerUser, [
            'title' => 'Corrugated boxes', 'quote_deadline' => now()->addHours(3)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'items' => [['name' => 'Box', 'qty' => 100, 'unit' => 'pcs']], 'terms' => ['freight' => 'included'],
        ]);
        $svc->invite($rfq, $this->buyerUser, BuyerSupplier::where('buyer_org_id', $this->buyer->id)->pluck('id')->all());
        $svc->publish($rfq->fresh(), $this->buyerUser);
        app(CurrentOrganization::class)->set(null);

        return Rfq::withoutGlobalScopes()->find($rfq->id);
    }

    public function test_off_until_configured(): void
    {
        Http::fake();
        $this->rfq();
        $this->assertSame(0, WhatsappMessage::count());
        Http::assertNothingSent();
    }

    public function test_rfq_invitation_reaches_every_supplier_including_those_not_on_getl1(): void
    {
        $this->enable();
        $rfq = $this->rfq();

        $sent = WhatsappMessage::where('template', 'rfq_invite')->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['919876500001', '919876500002', '919876500003'], $sent->pluck('recipient_phone')->all());
        $this->assertSame(['sent', 'sent', 'sent'], $sent->pluck('status')->all());
        $gamma = RfqInvite::where('rfq_id', $rfq->id)->whereNull('supplier_org_id')->firstOrFail();

        Http::assertSent(function (HttpRequest $r) use ($gamma) {
            $d = $r->data();

            return str_contains($r->url(), '/v21.0/1234567890/messages') && $r->hasHeader('Authorization', 'Bearer test-token')
                && $d['to'] === '919876500003' && $d['template']['name'] === 'getl1_rfq_invite'
                && $d['template']['components'][0]['parameters'][0]['text'] === 'Acme Buyers'
                && $d['template']['components'][0]['parameters'][1]['text'] === 'Corrugated boxes'
                && $d['template']['components'][1]['parameters'][0]['text'] === 'i/'.$gamma->token;
        });
    }

    public function test_po_approval_and_opt_outs(): void
    {
        $this->enable();
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $approver->forceFill(['phone' => '9811100000'])->save();
        $rfq = $this->rfq();
        foreach (['A' => 50, 'B' => 60] as $k => $p) {
            $invite = RfqInvite::where('rfq_id', $rfq->id)->where('supplier_org_id', $this->s[$k][0]->id)->firstOrFail();
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.accept', $invite->id), ['agree' => 1]);
            $this->actingAs($this->s[$k][1])->post(route('supplier.rfqs.quote', $invite->id), [
                'items' => [$rfq->items()->first()->id => ['unit_price' => $p, 'gst_rate' => '18', 'freight' => 0]], 'valid_till' => now()->addDays(10)->toDateString(),
            ])->assertSessionHasNoErrors();
            app(CurrentOrganization::class)->set(null);
        }
        $this->travel(4)->hours();
        $this->actingAs($this->buyerUser)->post(route('buyer.awards.store', $rfq->id), ['supplier_org_id' => $this->s['A'][0]->id])->assertSessionHasNoErrors();
        app(CurrentOrganization::class)->set(null);
        $award = Award::withoutGlobalScopes()->where('rfq_id', $rfq->id)->firstOrFail();

        $this->assertSame('919811100000', WhatsappMessage::where('template', 'approval_needed')->value('recipient_phone'));
        $this->actingAs($approver)->post(route('buyer.awards.approve', $award->id))->assertRedirect();
        $po = WhatsappMessage::where('template', 'po_issued')->firstOrFail();
        $this->assertSame('919876500001', $po->recipient_phone);
        $this->assertStringContainsString('supplier/orders/'.$award->id, $po->body);

        // Turned off in My profile: no more WhatsApp for that person.
        $approver->forceFill(['notification_prefs' => ['whatsapp' => false]])->save();
        \App\Services\Whatsapp::toUser($approver->fresh(), 'approval_needed', ['x', 'y', 'z'], 'buyer/rfqs/1');
        $this->assertSame(1, WhatsappMessage::where('template', 'approval_needed')->count());

        // A STOP reply stops everything to that number.
        WhatsappMessage::create(['recipient_phone' => '919876500002', 'direction' => 'in', 'body' => 'STOP', 'status' => 'opted_out']);
        \App\Services\Whatsapp::toPhone('9876500002', 'po_issued', ['a', 'b', 'c'], 'supplier/orders/1');
        $this->assertSame(0, WhatsappMessage::where('recipient_phone', '919876500002')->where('template', 'po_issued')->count());
    }

    public function test_rejected_by_meta_is_recorded_as_failed(): void
    {
        config(['whatsapp.phone_number_id' => '1', 'whatsapp.token' => 't']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 400)]);
        \App\Services\Whatsapp::toPhone('9876500009', 'po_issued', ['a', 'b', 'c'], 'supplier/orders/1');
        $m = WhatsappMessage::firstOrFail();
        $this->assertSame('failed', $m->status);
        $this->assertStringContainsString('Template name does not exist', $m->error);
        // Bad numbers never reach Meta.
        \App\Services\Whatsapp::toPhone('12345', 'po_issued', ['a', 'b', 'c'], 'x');
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_webhook_verification_receipts_and_stop(): void
    {
        $this->enable();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSee('42');

        $msg = WhatsappMessage::create(['recipient_phone' => '919876500001', 'direction' => 'out', 'template' => 'po_issued', 'status' => 'sent', 'provider_message_id' => 'wamid.X']);
        $post = function (array $payload, ?string $secret = 'shh') {
            $body = json_encode($payload);

            return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
            ], $body);
        };
        $status = fn ($s) => ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.X', 'status' => $s]]]]]]]];

        $post($status('read'), 'wrong-secret')->assertForbidden();
        $this->assertSame('sent', $msg->fresh()->status);
        $post($status('read'))->assertOk();
        $this->assertSame('read', $msg->fresh()->status);
        $post($status('delivered'))->assertOk(); // late receipt never moves it back
        $this->assertSame('read', $msg->fresh()->status);

        $post(['entry' => [['changes' => [['value' => ['messages' => [['from' => '919876500001', 'id' => 'wamid.in1', 'text' => ['body' => ' Stop ']]]]]]]]])->assertOk();
        $this->assertTrue(WhatsappMessage::where('recipient_phone', '919876500001')->where('status', 'opted_out')->exists());
        \App\Services\Whatsapp::toPhone('9876500001', 'po_issued', ['a', 'b', 'c'], 'x');
        $this->assertSame(0, WhatsappMessage::where('direction', 'out')->where('status', 'queued')->count());
    }
}
