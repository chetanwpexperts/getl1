<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SupplierDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class KycTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Throwaway disk in the system temp dir: works whichever user runs the tests
        // (storage/ on the server belongs to www-data).
        $this->diskRoot = sys_get_temp_dir().'/getl1-test-'.bin2hex(random_bytes(6));
        Storage::set('local', Storage::createLocalDriver(['root' => $this->diskRoot, 'throw' => true]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function pdf(string $name = 'gst.pdf', string $body = "1 0 obj << /Type /Catalog >> endobj\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body."%%EOF\n");
    }

    private function upload($user, UploadedFile $file, string $type = 'gst')
    {
        return $this->actingAs($user)->post('/supplier/documents', ['type' => $type, 'file' => $file]);
    }

    public function test_supplier_uploads_pdf_to_private_storage(): void
    {
        [$org, $user] = $this->supplier();

        $this->upload($user, $this->pdf())->assertRedirect()->assertSessionHasNoErrors();

        $doc = SupplierDocument::firstOrFail();
        $this->assertSame('pending', $doc->status);
        $this->assertStringStartsWith("kyc/{$org->id}/", $doc->file_path);
        $this->assertStringEndsWith('.pdf', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertTrue(AuditLog::where('action', 'kyc_document_uploaded')->exists());
    }

    public function test_php_file_renamed_to_pdf_is_rejected(): void
    {
        [, $user] = $this->supplier();

        $this->upload($user, UploadedFile::fake()->createWithContent('gst.pdf', '<?php system($_GET["c"]); ?>'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SupplierDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdf_with_javascript_is_rejected(): void
    {
        [, $user] = $this->supplier();

        $this->upload($user, $this->pdf('gst.pdf', "1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n"))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, SupplierDocument::count());
    }

    public function test_wrong_extension_is_rejected(): void
    {
        [, $user] = $this->supplier();

        $this->upload($user, UploadedFile::fake()->createWithContent('gst.svg', '<svg onload="alert(1)"/>'))
            ->assertSessionHasErrors('file');
    }

    public function test_only_owner_and_admin_can_download(): void
    {
        [, $owner] = $this->supplier('Owner Co');
        [, $otherSupplier] = $this->supplier('Other Co');
        [, $buyer] = $this->buyer();
        $this->upload($owner, $this->pdf());
        $doc = SupplierDocument::firstOrFail();

        $this->actingAs($owner)->get("/supplier/documents/{$doc->id}/download")->assertOk()->assertDownload();
        $this->actingAs($otherSupplier)->get("/supplier/documents/{$doc->id}/download")->assertNotFound();
        $this->actingAs($buyer)->get("/supplier/documents/{$doc->id}/download")->assertForbidden();
        $this->actingAs($buyer)->get("/admin/kyc/{$doc->id}/download")->assertForbidden();

        $this->asAdmin($this->admin())->get("/admin/kyc/{$doc->id}/download")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'kyc_document_viewed_by_admin')->exists());
    }

    public function test_other_supplier_cannot_delete(): void
    {
        [, $owner] = $this->supplier('Owner Co');
        [, $other] = $this->supplier('Other Co');
        $this->upload($owner, $this->pdf());
        $doc = SupplierDocument::firstOrFail();

        $this->actingAs($other)->delete("/supplier/documents/{$doc->id}")->assertNotFound();
        $this->assertNotNull($doc->fresh());
    }

    public function test_admin_approval_verifies_and_rejection_unverifies(): void
    {
        [$org, $user] = $this->supplier();
        $this->upload($user, $this->pdf());
        $doc = SupplierDocument::firstOrFail();
        $admin = $this->admin();

        $this->asAdmin($admin)->post("/admin/kyc/{$doc->id}/approve")->assertRedirect();
        $this->assertNotNull($org->fresh()->verified_at);

        $this->asAdmin($admin)->post("/admin/kyc/{$doc->id}/reject", ['remarks' => 'Name does not match'])->assertRedirect();
        $this->assertNull($org->fresh()->verified_at);
        $this->assertSame('Name does not match', $doc->fresh()->remarks);
    }

    public function test_verified_documents_cannot_be_deleted_by_supplier(): void
    {
        [, $user] = $this->supplier();
        $this->upload($user, $this->pdf());
        $doc = SupplierDocument::firstOrFail();
        $this->asAdmin($this->admin())->post("/admin/kyc/{$doc->id}/approve");

        $this->actingAs($user)->delete("/supplier/documents/{$doc->id}")->assertSessionHasErrors('file');
        $this->assertNotNull($doc->fresh());
    }

    public function test_changing_gstin_removes_verified_badge(): void
    {
        [$org, $user] = $this->supplier();
        $org->forceFill(['verified_at' => now(), 'gstin' => '27AAPFU0939F1ZV'])->save();

        $this->actingAs($user)->put('/company', [
            'name' => $org->name, 'city' => 'Ludhiana', 'gstin' => '29AAGCB7383J1Z4',
        ])->assertRedirect(route('company.edit'));

        $this->assertNull($org->fresh()->verified_at);
        $this->assertTrue(AuditLog::where('action', 'verification_reset')->exists());
    }

    public function test_gstin_cannot_be_claimed_by_two_companies(): void
    {
        [$first] = $this->supplier('First');
        $first->forceFill(['gstin' => '27AAPFU0939F1ZV'])->save();
        [, $user] = $this->supplier('Second');

        $this->actingAs($user)->put('/company', ['name' => 'Second', 'city' => 'Mohali', 'gstin' => '27AAPFU0939F1ZV'])
            ->assertSessionHasErrors('gstin');
    }

    public function test_pan_must_match_gstin(): void
    {
        [, $user] = $this->supplier();

        $this->actingAs($user)->put('/company', [
            'name' => 'S', 'city' => 'Mohali', 'gstin' => '27AAPFU0939F1ZV', 'pan' => 'AAGCB7383J',
        ])->assertSessionHasErrors('pan');
    }

    public function test_non_admins_cannot_reach_admin_pages(): void
    {
        [, $user] = $this->supplier();

        $this->actingAs($user)->get('/admin/kyc')->assertForbidden();
    }
}
