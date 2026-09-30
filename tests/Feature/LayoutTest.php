<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Every authenticated page must show the same header: company name, role badge and menu.
 */
class LayoutTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_admin_pages_keep_the_company_header_and_menu(): void
    {
        [, $user] = $this->buyer('Acme Buyers');
        $user->forceFill(['is_platform_admin' => true])->save();

        foreach (['/dashboard', '/buyer/suppliers', '/company', '/admin/kyc'] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Acme Buyers')
                ->assertSee('Suppliers')
                ->assertSee('Company')
                ->assertSee('KYC review');
        }
    }

    public function test_staff_link_is_hidden_from_customers(): void
    {
        [, $user] = $this->buyer();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('KYC review');
    }

    public function test_no_developer_wording_on_customer_pages(): void
    {
        [, $buyer] = $this->buyer();
        [, $supplier] = $this->supplier();

        foreach ([[$buyer, ['/dashboard', '/buyer/suppliers', '/buyer/suppliers/create', '/buyer/suppliers/import', '/company']],
                  [$supplier, ['/dashboard', '/supplier/documents', '/company']]] as [$user, $urls]) {
            foreach ($urls as $url) {
                $html = strtolower($this->actingAs($user)->get($url)->assertOk()->getContent());
                foreach (['build step', 'todo', 'lorem', 'coming in the next'] as $phrase) {
                    $this->assertStringNotContainsString($phrase, $html, "\"{$phrase}\" found on {$url}");
                }
            }
        }
    }
}
