<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Models\BuyerSupplier;
use App\Models\Category;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Database\Seeder;

/**
 * Local demo data. Password for every demo user: password
 *   buyer@getl1.test           → Demo Packaging Pvt Ltd (buyer admin)
 *   supplier1..5@getl1.test    → five box suppliers in the buyer's list
 */
class DemoSeeder extends Seeder
{
    public function run(OrganizationService $orgs): void
    {
        $buyerUser = User::factory()->create([
            'name' => 'Demo Buyer', 'email' => 'buyer@getl1.test', 'phone' => '9876500000',
        ]);
        $buyer = $orgs->createWithOwner($buyerUser, [
            'name' => 'Demo Packaging Pvt Ltd', 'city' => 'Mohali', 'state' => 'Punjab',
        ], OrganizationType::Buyer);

        $boxes = Category::where('slug', 'packaging-corrugated-boxes')->first();

        foreach (['Sharma Cartons', 'Punjab Box Works', 'Tricity Packers', 'Baddi Corrugation', 'Ludhiana Paper Products'] as $i => $name) {
            $n = $i + 1;
            $user = User::factory()->create([
                'name' => "Supplier {$n}", 'email' => "supplier{$n}@getl1.test", 'phone' => '98765000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            ]);
            $supplier = $orgs->createWithOwner($user, ['name' => $name, 'city' => ['Mohali', 'Ludhiana', 'Panchkula', 'Baddi', 'Ludhiana'][$i]], OrganizationType::Supplier);

            if ($boxes) {
                $supplier->categories()->syncWithoutDetaching([$boxes->id]);
            }

            BuyerSupplier::create([
                'buyer_org_id' => $buyer->id,
                'supplier_org_id' => $supplier->id,
                'company_name' => $name,
                'contact_name' => $user->name,
                'contact_email' => $user->email,
                'contact_phone' => $user->phone,
                'tag' => 'boxes',
            ]);
        }
    }
}
