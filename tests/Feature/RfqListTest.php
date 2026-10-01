<?php

namespace Tests\Feature;

use App\Models\Rfq;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class RfqListTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_tabs_counts_and_search_stay_inside_the_company(): void
    {
        $this->seed(PlanSeeder::class);
        [$org, $user] = $this->buyer();
        [$other, $otherUser] = $this->buyer('Other Co');
        $current = app(CurrentOrganization::class);

        $current->set($org);
        Rfq::create(['created_by' => $user->id, 'title' => 'Corrugated boxes', 'status' => 'draft']);
        Rfq::create(['created_by' => $user->id, 'title' => 'MS sheets 2mm', 'status' => 'published', 'quote_deadline' => now()->addDay()]);
        Rfq::create(['created_by' => $user->id, 'title' => 'BOPP tape', 'status' => 'awarded']);
        $current->set($other);
        Rfq::create(['created_by' => $otherUser->id, 'title' => 'Secret steel order', 'status' => 'published']);
        $current->set($org);

        $page = $this->actingAs($user)->get(route('buyer.rfqs.index'))->assertOk();
        $page->assertSee('Corrugated boxes')->assertSee('MS sheets 2mm')->assertSee('BOPP tape')->assertDontSee('Secret steel order');

        $this->actingAs($user)->get(route('buyer.rfqs.index', ['status' => 'active']))->assertOk()
            ->assertSee('MS sheets 2mm')->assertDontSee('Corrugated boxes')->assertDontSee('BOPP tape');
        $this->actingAs($user)->get(route('buyer.rfqs.index', ['status' => 'draft']))->assertSee('Corrugated boxes')->assertDontSee('MS sheets 2mm');
        $this->actingAs($user)->get(route('buyer.rfqs.index', ['q' => 'tape']))->assertSee('BOPP tape')->assertDontSee('MS sheets 2mm');
        $this->actingAs($user)->get(route('buyer.rfqs.index', ['q' => 'Secret']))->assertSee('No RFQs match');
        $this->actingAs($user)->get(route('buyer.rfqs.index', ['status' => 'bogus']))->assertOk()->assertSee('BOPP tape');
    }
}
