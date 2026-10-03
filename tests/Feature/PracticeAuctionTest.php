<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/** The practice room runs in the browser only: it renders the real screen and stores nothing. */
class PracticeAuctionTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_suppliers_get_a_practice_room_that_saves_nothing(): void
    {
        [, $user] = $this->supplier('Alpha Packaging');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Try a practice auction');
        $res = $this->actingAs($user)->get(route('supplier.auctions.practice'))->assertOk()
            ->assertSee('Practice auction.')->assertSee('Place a bid')->assertSee('&quot;practice&quot;:true', false);
        $this->assertStringNotContainsString(route('supplier.auctions.bid', 1), $res->getContent());
        $this->assertSame(0, Auction::withoutGlobalScopes()->count());
        $this->assertSame(0, Bid::count());

        // Buyers don't get it.
        [, $buyer] = $this->buyer('Acme');
        $this->actingAs($buyer)->get(route('supplier.auctions.practice'))->assertForbidden();
    }
}
