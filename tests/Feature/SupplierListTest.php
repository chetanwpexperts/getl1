<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\BuyerSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;
use Tests\Unit\SpreadsheetReaderTest;

class SupplierListTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_buyer_adds_supplier_and_it_is_audited(): void
    {
        [$buyer, $user] = $this->buyer();

        $this->actingAs($user)->post('/buyer/suppliers', [
            'company_name' => '  Sharma   Cartons ',
            'contact_phone' => '+91 98765-43210',
            'contact_email' => 'Ravi@Sharma.IN',
        ])->assertRedirect(route('buyer.suppliers.index'));

        $entry = BuyerSupplier::firstOrFail();
        $this->assertSame('Sharma Cartons', $entry->company_name);
        $this->assertSame('9876543210', $entry->contact_phone);
        $this->assertSame('ravi@sharma.in', $entry->contact_email);
        $this->assertSame($buyer->id, $entry->buyer_org_id);
        $this->assertTrue(AuditLog::where('action', 'supplier_list_added')->where('organization_id', $buyer->id)->exists());
    }

    public function test_links_to_a_registered_supplier(): void
    {
        [, $user] = $this->buyer();
        [$supplier] = $this->supplier('Punjab Box Works', ['email' => 'owner@pbw.in']);

        $this->actingAs($user)->post('/buyer/suppliers', ['company_name' => 'PBW', 'contact_email' => 'owner@pbw.in']);

        $this->assertSame($supplier->id, BuyerSupplier::firstOrFail()->supplier_org_id);
    }

    public function test_never_links_to_a_buyer_org(): void
    {
        [, $user] = $this->buyer('Buyer A');
        $this->buyer('Buyer B', ['email' => 'b@buyer.in']);

        $this->actingAs($user)->post('/buyer/suppliers', ['company_name' => 'X', 'contact_email' => 'b@buyer.in']);

        $this->assertNull(BuyerSupplier::firstOrFail()->supplier_org_id);
    }

    public function test_needs_phone_or_email_and_rejects_duplicates(): void
    {
        [, $user] = $this->buyer();
        $this->actingAs($user);

        $this->post('/buyer/suppliers', ['company_name' => 'No contact'])->assertSessionHasErrors(['contact_email', 'contact_phone']);
        $this->post('/buyer/suppliers', ['company_name' => 'A', 'contact_phone' => '9876543210']);
        $this->post('/buyer/suppliers', ['company_name' => 'B', 'contact_phone' => '09876543210'])->assertSessionHasErrors('contact_email');

        $this->assertSame(1, BuyerSupplier::count());
    }

    public function test_other_buyers_entries_are_invisible_and_untouchable(): void
    {
        [$buyerA, $userA] = $this->buyer('Buyer A');
        [, $userB] = $this->buyer('Buyer B');
        $entry = BuyerSupplier::create(['buyer_org_id' => $buyerA->id, 'company_name' => 'Secret Supplier', 'contact_phone' => '9876543210']);

        $this->actingAs($userB);
        $this->get('/buyer/suppliers')->assertOk()->assertDontSee('Secret Supplier');
        $this->get("/buyer/suppliers/{$entry->id}/edit")->assertNotFound();
        $this->put("/buyer/suppliers/{$entry->id}", ['company_name' => 'Hacked', 'contact_phone' => '9876543211'])->assertNotFound();
        $this->post("/buyer/suppliers/{$entry->id}/block")->assertNotFound();
        $this->delete("/buyer/suppliers/{$entry->id}")->assertNotFound();

        $this->assertSame('Secret Supplier', $entry->fresh()->company_name);
        $this->assertSame('active', $entry->fresh()->status);
    }

    public function test_suppliers_and_approvers_cannot_manage_the_list(): void
    {
        [$buyer] = $this->buyer();
        [, $supplierUser] = $this->supplier();
        $approver = $this->memberOf($buyer, OrgRole::Approver);

        $this->actingAs($supplierUser)->get('/buyer/suppliers')->assertForbidden();

        $this->actingAs($approver)->get('/buyer/suppliers')->assertOk();
        $this->actingAs($approver)->post('/buyer/suppliers', ['company_name' => 'X', 'contact_phone' => '9876543210'])->assertForbidden();
    }

    public function test_block_and_remove(): void
    {
        [$buyer, $user] = $this->buyer();
        $entry = BuyerSupplier::create(['buyer_org_id' => $buyer->id, 'company_name' => 'S', 'contact_phone' => '9876543210']);
        $this->actingAs($user);

        $this->post("/buyer/suppliers/{$entry->id}/block");
        $this->assertSame('blocked', $entry->fresh()->status);

        $this->delete("/buyer/suppliers/{$entry->id}")->assertRedirect();
        $this->assertNull($entry->fresh());
        $this->assertTrue(AuditLog::where('action', 'supplier_list_removed')->exists());
    }

    public function test_search_treats_wildcards_literally(): void
    {
        [$buyer, $user] = $this->buyer();
        BuyerSupplier::create(['buyer_org_id' => $buyer->id, 'company_name' => 'Alpha', 'contact_phone' => '9876543210']);

        $this->actingAs($user)->get('/buyer/suppliers?q=%25')->assertOk()->assertDontSee('Alpha');
    }

    public function test_csv_import_adds_skips_and_reports(): void
    {
        [$buyer, $user] = $this->buyer();
        BuyerSupplier::create(['buyer_org_id' => $buyer->id, 'company_name' => 'Existing', 'contact_phone' => '9876500001']);

        $csv = "Company Name,Contact Person,Mobile,Email,Tag\n"
            ."Tricity Packers,Aman,9876500002,,Boxes\n"
            ."Existing again,,98765 00001,,\n"      // duplicate of existing → skipped
            ."Bad phone,,12345,,\n"                 // invalid → error
            ."Tricity dup,,9876500002,,\n"          // duplicate inside file → skipped
            ."Email only,,,mail@x.in,\n";

        $this->actingAs($user)->post('/buyer/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('suppliers.csv', $csv),
        ])->assertRedirect(route('buyer.suppliers.index'))->assertSessionHas('import_errors');

        $this->assertEqualsCanonicalizing(
            ['Existing', 'Tricity Packers', 'Email only'],
            BuyerSupplier::where('buyer_org_id', $buyer->id)->pluck('company_name')->all()
        );
        $this->assertSame('boxes', BuyerSupplier::where('company_name', 'Tricity Packers')->value('tag'));
        $this->assertTrue(AuditLog::where('action', 'supplier_list_imported')->exists());
    }

    public function test_xlsx_import(): void
    {
        [$buyer, $user] = $this->buyer();
        $path = SpreadsheetReaderTest::makeXlsx([
            ['Supplier', 'WhatsApp'],
            ['Baddi Corrugation', 9876500003],
        ]);

        $this->actingAs($user)->post('/buyer/suppliers/import', [
            'file' => new UploadedFile($path, 'list.xlsx', null, null, true),
        ])->assertRedirect(route('buyer.suppliers.index'));

        $this->assertSame('9876500003', BuyerSupplier::where('buyer_org_id', $buyer->id)->value('contact_phone'));
        @unlink($path);
    }

    public function test_import_rejects_files_that_are_not_spreadsheets(): void
    {
        [, $user] = $this->buyer();

        $this->actingAs($user)->post('/buyer/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('list.xlsx', '<?php system($_GET["c"]);'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($user)->post('/buyer/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('shell.php', 'x'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, BuyerSupplier::count());
    }
}
