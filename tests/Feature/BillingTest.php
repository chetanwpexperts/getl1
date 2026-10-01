<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Enums\RfqStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\PaymentReceiptMail;
use App\Models\Auction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Auction\AuctionService;
use App\Services\Billing\PlanService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private Organization $buyer;
    private User $admin;
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Mail::fake();
        $this->diskRoot = sys_get_temp_dir().'/getl1-billing-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'test_secret',
            'services.razorpay.webhook_secret' => 'hook_secret',
            'billing.gst_enabled' => false,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_test1']),
            'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_test1', 'status' => 'created']),
            'api.razorpay.com/v1/subscriptions/*/cancel' => Http::response(['id' => 'sub_test1', 'status' => 'active']),
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_test1', 'amount' => 0]),
        ]);

        $this->travelTo(Carbon::parse('2026-10-05 04:30:00', 'UTC'));
        [$this->buyer, $this->admin] = $this->buyer('Acme Buyers');
        app(CurrentOrganization::class)->set(null);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function sig(string $payload, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private function plans(): PlanService
    {
        return app(PlanService::class);
    }

    /** An RFQ with 2 sealed quotes, deadline passed, ready for an auction. */
    private function rfqReady(): Rfq
    {
        $rfq = Rfq::create(['organization_id' => $this->buyer->id, 'created_by' => $this->admin->id, 'title' => 'Boxes '.uniqid(),
            'status' => RfqStatus::Published, 'quote_deadline' => now()->subMinute(), 'published_at' => now()->subDay()]);
        foreach (['S1', 'S2'] as $i => $name) {
            [$s, $u] = $this->supplier($name.' '.uniqid());
            Quote::create(['rfq_id' => $rfq->id, 'supplier_org_id' => $s->id, 'submitted_by' => $u->id,
                'total' => 100000 + $i * 1000, 'submitted_at' => now()->subHours(2)]);
        }
        app(CurrentOrganization::class)->set(null);

        return $rfq;
    }

    private function schedule(Rfq $rfq): Auction
    {
        return app(AuctionService::class)->schedule($rfq, $this->admin, [
            'starts_at' => now()->addMinutes(10)->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'duration_min' => 10, 'min_decrement_type' => 'percent', 'min_decrement_value' => '0.5', 'max_decrement_pct' => '10',
            'extend_window_sec' => 0, 'extend_by_sec' => 60, 'max_extensions' => 0, 'visibility' => 'rank_only',
        ]);
    }

    // ---------------------------------------------------------------- plans and limits

    public function test_new_buyer_tries_growth_then_continues_on_free(): void
    {
        $this->assertSame('growth', $this->plans()->current($this->buyer)->code);
        $this->assertTrue($this->plans()->hasFeature($this->buyer, 'savings_report'));

        $this->travel(15)->days();
        $this->assertSame('free', $this->plans()->current($this->buyer)->code);
        $this->assertFalse($this->plans()->hasFeature($this->buyer, 'savings_report'));
        $this->assertSame(1, $this->plans()->auctionAllowance($this->buyer)['limit']);
    }

    public function test_free_plan_limit_then_credits_then_refund_on_cancel(): void
    {
        $this->travel(15)->days(); // trial over → Free: 1 auction a month
        $first = $this->schedule($this->rfqReady());
        $this->assertFalse($first->paid_with_credit);

        try {
            $this->schedule($this->rfqReady());
            $this->fail('Second auction allowed on Free');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('buy a single auction', $e->getMessage());
        }

        $this->buyer->forceFill(['auction_credits' => 1])->save();
        $second = $this->schedule($this->rfqReady());
        $this->assertTrue($second->paid_with_credit);
        $this->assertSame(0, $this->buyer->fresh()->auction_credits);

        app(AuctionService::class)->cancel($second, $this->admin, 'Changed our plans');
        $this->assertSame(1, $this->buyer->fresh()->auction_credits, 'Credit returned when cancelled before it ran');

        // Next month the allowance resets.
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());
        $this->assertSame(1, $this->plans()->auctionAllowance($this->buyer)['left']);
    }

    // ---------------------------------------------------------------- auction credits

    public function test_buy_credits_with_verified_payment_once(): void
    {
        $res = $this->actingAs($this->admin)->postJson(route('buyer.billing.credits'), ['quantity' => 2])->assertOk()
            ->assertJsonPath('order_id', 'order_test1')->assertJsonPath('amount', 159800)->assertJsonPath('key', 'rzp_test_key');
        $this->assertArrayNotHasKey('key_secret', $res->json());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.razorpay.com/v1/orders' && $r['amount'] === 159800);

        // Forged response: nothing credited.
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits.confirm'), [
            'razorpay_payment_id' => 'pay_1', 'razorpay_order_id' => 'order_test1', 'razorpay_signature' => 'forged',
        ])->assertUnprocessable();
        $this->assertSame(0, $this->buyer->fresh()->auction_credits);

        $ok = ['razorpay_payment_id' => 'pay_1', 'razorpay_order_id' => 'order_test1', 'razorpay_signature' => $this->sig('order_test1|pay_1')];
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits.confirm'), $ok)->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits.confirm'), $ok)->assertOk();
        $this->assertSame(2, $this->buyer->fresh()->auction_credits, 'Credited exactly once');

        $p = Payment::firstOrFail();
        $this->assertSame('paid', $p->status);
        $this->assertSame('GL1/2026-27/0001', $p->invoice_number);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($p->invoice_pdf_path));
        Mail::assertQueued(PaymentReceiptMail::class, 1);

        $this->actingAs($this->admin)->get(route('buyer.billing.invoice', $p->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        [, $rival] = $this->buyer('Rival Industries');
        $this->actingAs($rival)->get(route('buyer.billing.invoice', $p->id))->assertNotFound();
    }

    public function test_webhook_is_signed_and_processed_once(): void
    {
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits'), ['quantity' => 3])->assertOk();
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_9', 'order_id' => 'order_test1', 'invoice_id' => null, 'status' => 'captured',
        ]]]]);
        $send = fn (string $sig, string $id = 'evt_1') => $this->call('POST', route('webhooks.razorpay'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig, 'HTTP_X_RAZORPAY_EVENT_ID' => $id], $body);

        $send('bad')->assertStatus(400);
        $this->assertSame(0, $this->buyer->fresh()->auction_credits);

        $send($this->sig($body, 'hook_secret'))->assertOk();
        $send($this->sig($body, 'hook_secret'))->assertOk();
        $send($this->sig($body, 'hook_secret'), 'evt_2')->assertOk(); // different event, same payment
        $this->assertSame(3, $this->buyer->fresh()->auction_credits);
        $this->assertSame(1, Payment::where('status', 'paid')->count());
    }

    // ---------------------------------------------------------------- subscriptions

    public function test_subscribe_confirm_renew_and_cancel(): void
    {
        $this->actingAs($this->admin)->postJson(route('buyer.billing.subscribe'), ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('subscription_id', 'sub_test1');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.razorpay.com/v1/plans' && $r['item']['amount'] === 149900 && $r['period'] === 'monthly');
        $this->assertSame('growth', $this->plans()->current($this->buyer)->code, 'Still on trial until paid');

        $this->actingAs($this->admin)->postJson(route('buyer.billing.subscribe.confirm'), [
            'razorpay_payment_id' => 'pay_2', 'razorpay_subscription_id' => 'sub_test1', 'razorpay_signature' => $this->sig('pay_2|sub_test1'),
        ])->assertOk();

        $this->assertSame('starter', $this->plans()->current($this->buyer)->code);
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::where('status', 'cancelled')->firstOrFail()->status, 'Trial ended');
        $this->assertEquals(1499, (float) Payment::where('razorpay_payment_id', 'pay_2')->value('total'));

        // Renewal arrives by webhook; the same payment twice is recorded once.
        $end = now()->addMonths(2)->getTimestamp();
        $body = json_encode(['event' => 'subscription.charged', 'payload' => [
            'subscription' => ['entity' => ['id' => 'sub_test1', 'current_start' => now()->addMonth()->getTimestamp(), 'current_end' => $end]],
            'payment' => ['entity' => ['id' => 'pay_3', 'order_id' => 'order_x', 'invoice_id' => 'inv_1']],
        ]]);
        foreach (['evt_a', 'evt_b'] as $id) {
            $this->call('POST', route('webhooks.razorpay'), [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $this->sig($body, 'hook_secret'), 'HTTP_X_RAZORPAY_EVENT_ID' => $id], $body)->assertOk();
        }
        $this->assertSame(2, Payment::where('status', 'paid')->count());
        $this->assertSame($end, Subscription::where('razorpay_subscription_id', 'sub_test1')->first()->current_period_end->getTimestamp());

        // Cancel: stops renewal at period end; plan kept until then.
        $this->actingAs($this->admin)->post(route('buyer.billing.cancel'))->assertRedirect();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/subscriptions/sub_test1/cancel') && $r['cancel_at_cycle_end'] === 1);
        $this->assertTrue(Subscription::where('razorpay_subscription_id', 'sub_test1')->first()->cancel_at_period_end);
        $this->assertSame('starter', $this->plans()->current($this->buyer)->code);

        $this->actingAs($this->admin)->get(route('buyer.billing.index'))->assertOk()->assertSee('won’t renew', false)->assertSee('GL1/2026-27/0002');
    }

    public function test_only_admins_pay_and_contact_sales_plans_are_not_sold_online(): void
    {
        $approver = $this->memberOf($this->buyer, OrgRole::Approver);
        $this->actingAs($approver)->postJson(route('buyer.billing.subscribe'), ['plan' => 'starter', 'cycle' => 'monthly'])->assertForbidden();
        $this->actingAs($approver)->get(route('buyer.billing.index'))->assertOk()->assertSee('Ask your company admin');

        $this->actingAs($this->admin)->postJson(route('buyer.billing.subscribe'), ['plan' => 'business', 'cycle' => 'monthly'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('buyer.billing.subscribe'), ['plan' => 'free', 'cycle' => 'monthly'])->assertUnprocessable();

        [, $supplierUser] = $this->supplier('Some Supplier');
        $this->actingAs($supplierUser)->get(route('buyer.billing.index'))->assertForbidden();
    }

    public function test_billing_page_and_gst(): void
    {
        $page = $this->actingAs($this->admin)->get(route('buyer.billing.index'))->assertOk();
        $page->assertSee('Starter')->assertSee('₹1,499')->assertSee('Talk to us')->assertSee('Free trial')->assertSee('Test mode');

        config(['billing.gst_enabled' => true]);
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits'), ['quantity' => 1])->assertOk()->assertJsonPath('amount', 94282);
        $this->assertEquals(143.82, (float) Payment::latest('id')->value('gst_amount'));
    }

    public function test_payments_off_until_keys_are_set(): void
    {
        config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null]);
        $this->actingAs($this->admin)->get(route('buyer.billing.index'))->assertOk()->assertSee('Online payment is being set up');
        $this->actingAs($this->admin)->postJson(route('buyer.billing.credits'), ['quantity' => 1])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
