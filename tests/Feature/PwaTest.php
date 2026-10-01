<?php

namespace Tests\Feature;

use App\Http\Controllers\PwaController;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_manifest_worker_and_offline_page(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('start_url', '/start')->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');
        foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png', 'apple-touch-icon.png'] as $icon) {
            $this->assertFileExists(public_path('icons/'.$icon));
        }

        $sw = $this->get('/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('no-store', $sw->headers->get('Cache-Control'));
        $this->assertStringContainsString("const VERSION = '".PwaController::VERSION."'", $sw->getContent());
        $this->assertStringNotContainsString('__VERSION__', $sw->getContent());

        $this->get('/offline')->assertOk()->assertSee("You're offline");
    }

    public function test_home_screen_icon_opens_the_right_page(): void
    {
        $this->get('/start')->assertRedirect(route('login'));
        [, $buyer] = $this->buyer();
        $this->actingAs($buyer)->get('/start')->assertRedirect(route('dashboard'));
    }

    public function test_install_tags_on_app_pages_only(): void
    {
        $this->get('/login')->assertOk()->assertSee('manifest.webmanifest', false)->assertSee('data-install-app', false);
        $this->get('/')->assertOk()->assertDontSee('manifest.webmanifest', false);
        [, $buyer] = $this->buyer();
        $this->actingAs($buyer)->get(route('dashboard'))->assertOk()->assertSee('manifest.webmanifest', false)->assertSee('Install app');
    }

    public function test_works_in_website_mode_for_staff(): void
    {
        config(['site.mode' => 'website']);
        foreach (['/manifest.webmanifest', '/sw.js', '/offline'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/start')->assertRedirect(route('login'));
        $admin = $this->admin();
        $this->actingAs($admin)->get('/start')->assertRedirect(route('admin.dashboard'));
    }
}
