<?php

namespace Tests\Feature;

use App\Mail\LeadReceivedMail;
use App\Mail\LeadThanksMail;
use App\Models\Lead;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Mail::fake();
        config(['site.leads_to' => 'sales@getl1.com']);
    }

    private function lead(array $over = []): array
    {
        return array_merge([
            't' => encrypt(time() - 20), 'interest' => 'buyer', 'name' => 'Ravi Kumar', 'company' => 'Ravi Packaging',
            'email' => 'Ravi@Ravipack.in', 'phone' => '+91 98765 43210', 'city' => 'Ludhiana', 'monthly_spend' => '5l_25l',
            'message' => 'Corrugated boxes and tape', 'source' => 'linkedin',
        ], $over);
    }

    public function test_public_pages_load_with_real_prices_and_policies(): void
    {
        $this->get('/')->assertOk()->assertSee('Make your suppliers compete.')->assertSee('How it works')->assertSee('Start 14-day free trial');
        $this->get('/pricing')->assertOk()->assertSee('₹1,499')->assertSee('₹3,999')->assertSee('Talk to us')
            ->assertSee('AI requirement reading')->assertSee('₹799')->assertSee('₹199')->assertDontSee('WhatsApp alerts');
        $this->get('/for-suppliers')->assertOk()->assertSee('Free, always');
        $this->get('/contact')->assertOk()->assertSee('Book a demo');
        foreach (['/terms' => 'Terms of use', '/privacy' => 'Grievance Officer', '/refunds' => '5–7 working days', '/shipping' => 'does not sell or ship'] as $url => $text) {
            $this->get($url)->assertOk()->assertSee($text)->assertSee(config('site.legal_name'));
        }
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->assertSee(route('site.refunds'), false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /'); // not production: never indexed
    }

    public function test_lead_is_saved_and_both_sides_are_emailed(): void
    {
        $this->post('/contact', $this->lead())->assertRedirect(route('site.contact.thanks'));
        $lead = Lead::firstOrFail();
        $this->assertSame('ravi@ravipack.in', $lead->email);
        $this->assertSame('9876543210', $lead->phone);
        $this->assertSame('new', $lead->status);
        $this->assertSame('linkedin', $lead->source);
        Mail::assertQueued(LeadReceivedMail::class, fn ($m) => $m->hasTo('sales@getl1.com'));
        Mail::assertQueued(LeadThanksMail::class, fn ($m) => $m->hasTo('ravi@ravipack.in'));

        // Sending again the same day updates the request, without more emails.
        $this->post('/contact', $this->lead(['message' => 'Also stretch film']))->assertRedirect();
        $this->assertSame(1, Lead::count());
        $this->assertSame('Also stretch film', Lead::first()->message);
        Mail::assertQueued(LeadThanksMail::class, 1);

        $this->get(route('site.contact.thanks'))->assertOk()->assertSee('within one working day');
    }

    public function test_bots_and_bad_input_are_kept_out(): void
    {
        $this->post('/contact', $this->lead(['website' => 'http://spam.example']))->assertRedirect(route('site.contact.thanks'));
        $this->post('/contact', $this->lead(['t' => encrypt(time())]))->assertRedirect(route('site.contact.thanks')); // too fast
        $this->post('/contact', $this->lead(['t' => 'garbage']))->assertRedirect(route('site.contact.thanks'));
        $this->assertSame(0, Lead::count());
        Mail::assertNothingQueued();

        $this->post('/contact', $this->lead(['phone' => '12345']))->assertSessionHasErrors('phone');
        $this->post('/contact', $this->lead(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->post('/contact', $this->lead(['interest' => 'admin']))->assertSessionHasErrors('interest');
        $this->assertSame(0, Lead::count());
    }

    public function test_website_mode_shows_only_the_website(): void
    {
        config(['site.mode' => 'website']);

        $this->get('/')->assertOk()->assertSee('Get early access')->assertDontSee('Log in')->assertDontSee(route('register'));
        $this->get('/pricing')->assertOk()->assertSee('Get early access');
        $this->get('/contact')->assertOk()->assertSee('Request early access');
        foreach (['/login', '/register', '/dashboard', '/admin', '/buyer/rfqs'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/contact', $this->lead())->assertRedirect(route('site.contact.thanks'));
        $this->assertSame(1, Lead::count());
        $this->get('/robots.txt')->assertSee('Disallow: /admin')->assertSee('Sitemap:');
    }

    public function test_logged_in_users_see_their_dashboard_link(): void
    {
        [, $user] = $this->buyer();
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Dashboard');
    }
}
