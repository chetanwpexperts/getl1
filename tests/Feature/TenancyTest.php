<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\OrgRole;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\Rfq;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function buyerWithUser(string $name): array
    {
        $user = User::factory()->create();
        $org = app(OrganizationService::class)->createWithOwner($user, ['name' => $name, 'city' => 'Mohali'], OrganizationType::Buyer);

        return [$org, $user];
    }

    public function test_rfqs_are_scoped_to_the_current_organization(): void
    {
        [$orgA, $userA] = $this->buyerWithUser('Buyer A');
        [$orgB, $userB] = $this->buyerWithUser('Buyer B');
        $current = app(CurrentOrganization::class);

        $current->set($orgA);
        Rfq::create(['created_by' => $userA->id, 'title' => 'Boxes for A']);

        $current->set($orgB);
        Rfq::create(['created_by' => $userB->id, 'title' => 'Boxes for B']);

        $this->assertSame(['Boxes for B'], Rfq::pluck('title')->all());

        $current->set($orgA);
        $this->assertSame(['Boxes for A'], Rfq::pluck('title')->all());
        $this->assertSame($orgA->id, Rfq::first()->organization_id);
    }

    public function test_rfq_ref_numbers_are_sequential_per_organization(): void
    {
        [$orgA, $userA] = $this->buyerWithUser('Buyer A');
        app(CurrentOrganization::class)->set($orgA);

        $first = Rfq::create(['created_by' => $userA->id, 'title' => 'One']);
        $second = Rfq::create(['created_by' => $userA->id, 'title' => 'Two']);

        $year = now()->year;
        $this->assertSame("RFQ-{$year}-0001", $first->ref_no);
        $this->assertSame("RFQ-{$year}-0002", $second->ref_no);
    }

    public function test_dashboard_requires_login_and_an_organization(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $orphan = User::factory()->create();
        $this->actingAs($orphan)->get('/dashboard')->assertRedirect(route('onboarding'));
    }

    public function test_buyer_and_supplier_see_their_own_dashboards(): void
    {
        [, $buyerUser] = $this->buyerWithUser('Buyer A');
        $this->actingAs($buyerUser)->get('/dashboard')->assertOk()->assertSee('Open RFQs');

        $supplierUser = User::factory()->create();
        app(OrganizationService::class)->createWithOwner($supplierUser, ['name' => 'Sharma Cartons', 'city' => 'Ludhiana'], OrganizationType::Supplier);
        $this->actingAs($supplierUser)->get('/dashboard')->assertOk()->assertSee('Invitations');
    }

    public function test_user_cannot_switch_into_an_organization_they_do_not_belong_to(): void
    {
        [, $userA] = $this->buyerWithUser('Buyer A');
        [$orgB] = $this->buyerWithUser('Buyer B');

        $this->actingAs($userA)->post(route('organizations.switch', $orgB))->assertForbidden();
    }

    public function test_supplier_role_cannot_be_given_in_a_buyer_org(): void
    {
        [$org] = $this->buyerWithUser('Buyer A');
        $user = User::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(OrganizationService::class)->addMember($org, $user, OrgRole::SupplierUser);
    }

    public function test_bids_are_append_only(): void
    {
        [$buyer, $buyerUser] = $this->buyerWithUser('Buyer A');
        $supplier = Organization::factory()->supplier()->create();
        app(CurrentOrganization::class)->set($buyer);

        $rfq = Rfq::create(['created_by' => $buyerUser->id, 'title' => 'Boxes']);
        $auction = $rfq->auctions()->create([
            'organization_id' => $buyer->id,
            'start_price' => 100000,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'original_ends_at' => now()->addMinutes(30),
        ]);

        $bid = Bid::create([
            'auction_id' => $auction->id,
            'supplier_org_id' => $supplier->id,
            'user_id' => $buyerUser->id,
            'amount' => 99000,
        ]);

        $this->expectException(LogicException::class);
        $bid->update(['amount' => 1]);
    }
}
