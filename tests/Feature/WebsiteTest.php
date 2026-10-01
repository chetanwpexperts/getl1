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
        foreach (['/register', '/dashboard', '/buyer/rfqs', '/onboarding', '/supplier/rfqs'] as $url) {
            $this->get($url)->assertNotFound();
        }
        // Staff can still reach the admin console; customers can't log in before launch.
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
        $this->get('/admin')->assertRedirect(route('login'));
        [, $customer] = $this->buyer();
        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $admin = $this->admin();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->app['auth']->forgetGuards();
        $this->post('/contact', $this->lead())->assertRedirect(route('site.contact.thanks'));
        $this->assertSame(1, Lead::count());
        $this->get('/robots.txt')->assertSee('Disallow: /admin')->assertSee('Sitemap:');
    }

    public function test_logged_in_users_see_their_dashboard_link(): void
    {
        [, $user] = $this->buyer();
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Dashboard');
    }

    public function test_old_waitlist_imports_once(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'wl');
        file_put_contents($csv, "created_at,email,role,company,city,source,ip\n"
            ."2026-09-20T10:00:00+00:00,Owner@Acme.in,buyer,Acme Packaging,Ludhiana,linkedin,1.2.3.4\n"
            ."2026-09-21T10:00:00+00:00,sales@steel.in,supplier,=HYPERLINK(1),Mohali,,\n"
            ."bad,not-an-email,buyer,X,Y,,\n");

        $this->artisan('getl1:import-waitlist', ['path' => $csv])->expectsOutput('Waitlist: 2 added, 1 skipped.')->assertExitCode(0);
        $this->artisan('getl1:import-waitlist', ['path' => $csv])->expectsOutput('Waitlist: 0 added, 3 skipped.');
        $a = Lead::where('email', 'owner@acme.in')->firstOrFail();
        $this->assertSame('Acme Packaging', $a->company);
        $this->assertSame('2026-09-20', $a->created_at->toDateString());
        $this->assertSame('supplier', Lead::where('email', 'sales@steel.in')->value('interest'));
        $this->assertStringStartsNotWith('=', Lead::where('email', 'sales@steel.in')->value('company'));
        $this->artisan('getl1:leads')->assertExitCode(0);
        unlink($csv);
    }

    public function test_admin_can_edit_branding_contact_and_announcement(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $admin = $this->admin();
        $png = \Illuminate\Http\UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAIAAAADnC86AAAAK0lEQVR4nO3NMQ0AAAwDoPo33ZpYsgcMkD6JWCwWi8VisVgsFovFYrFYfGcs0K5PemaPnAAAAABJRU5ErkJggg=='));

        $this->asAdmin($admin)->get(route('admin.website'))->assertOk()->assertSee('Registered business name')->assertSee('Announcement bar');
        $this->asAdmin($admin)->post(route('admin.website.update'), ['ga4_id' => 'UA-123', 'email' => 'not-an-email'])->assertSessionHasErrors(['ga4_id', 'email']);
        $this->asAdmin($admin)->post(route('admin.website.update'), ['logo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')])->assertSessionHasErrors('logo');

        $this->asAdmin($admin)->post(route('admin.website.update'), [
            'logo' => $png, 'legal_name' => 'Sharma Ventures', 'email' => 'notifications@getl1.com', 'whatsapp' => '+91 98765 43210',
            'announcement_on' => '1', 'announcement_text' => 'Early access is open for October', 'hero_headline' => 'Buy smarter with live auctions',
            'social_linkedin' => 'https://www.linkedin.com/company/getl1', 'ga4_id' => 'G-ABC123XYZ', 'policies_updated' => '2026-10-02',
        ])->assertRedirect();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'admin_website_changed')->exists());
        $logo = \Illuminate\Support\Facades\DB::table('platform_settings')->where('key', 'website.logo')->value('value');
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists(json_decode($logo));

        $this->get('/')->assertOk()->assertSee('Early access is open for October')->assertSee('Buy smarter with live auctions')
            ->assertSee('Sharma Ventures')->assertSee('linkedin.com/company/getl1', false)->assertSee('G-ABC123XYZ', false)->assertSee('storage/site/logo-', false);
        $this->get('/contact')->assertSee('wa.me/919876543210', false)->assertSee('notifications@getl1.com');
        $this->get('/privacy')->assertSee('Google Analytics')->assertSee('2 October 2026');

        // Remove the logo again: back to the text logo.
        $this->asAdmin($admin)->post(route('admin.website.update'), ['remove_logo' => '1', 'legal_name' => 'Sharma Ventures', 'email' => 'notifications@getl1.com',
            'whatsapp' => '919876543210', 'announcement_on' => '1', 'announcement_text' => 'Early access is open for October', 'hero_headline' => 'Buy smarter with live auctions',
            'social_linkedin' => 'https://www.linkedin.com/company/getl1', 'ga4_id' => 'G-ABC123XYZ', 'policies_updated' => '2026-10-02'])->assertRedirect();
        $this->get('/')->assertDontSee('storage/site/logo-', false);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing(json_decode($logo));
    }

    public function test_turnstile_bot_check_when_enabled(): void
    {
        config(['services.turnstile.site_key' => '0x4AAAAAAAtest', 'services.turnstile.secret_key' => 'secret']);
        $this->get('/contact')->assertOk()->assertSee('cf-turnstile', false)->assertSee('challenges.cloudflare.com', false);
        $this->get('/login')->assertSee('cf-turnstile', false);

        // No token: refused before anything is saved.
        $this->post('/contact', $this->lead())->assertSessionHasErrors('turnstile');
        \Illuminate\Support\Facades\Http::fake([\App\Services\Turnstile::URL => \Illuminate\Support\Facades\Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true])]);
        $this->post('/contact', $this->lead(['cf-turnstile-response' => 'bad']))->assertSessionHasErrors('turnstile');
        $this->assertSame(0, Lead::count());
        $this->post('/contact', $this->lead(['cf-turnstile-response' => 'good-token']))->assertRedirect(route('site.contact.thanks'));
        $this->assertSame(1, Lead::count());
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r['secret'] === 'secret' && $r['response'] === 'good-token');

        [, $user] = $this->buyer();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('turnstile');
        $this->assertGuest();
    }
}
