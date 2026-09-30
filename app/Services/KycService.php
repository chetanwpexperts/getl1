<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SupplierDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supplier KYC documents (GST / PAN / Udyam certificates).
 *
 * Security:
 * - Stored on the private disk under kyc/{org}/{uuid}.{ext}. Never web-accessible,
 *   only streamed by controllers after an ownership/admin check.
 * - File type decided from the file's bytes, not its name. PDFs carrying active
 *   content (JavaScript, launch actions, embedded files) are refused.
 * - A GST or Udyam document approved by a GetL1 admin gives the supplier its verified badge.
 */
class KycService
{
    public const MAX_DOCUMENTS = 10;
    public const MAX_KB = 5120;

    /** Types that, once approved, verify the business. */
    private const VERIFYING_TYPES = ['gst', 'udyam'];

    public function __construct(private AuditLogger $audit, private FileGuard $guard) {}

    public function store(Organization $org, User $user, string $type, UploadedFile $file): SupplierDocument
    {
        $active = $org->documents()->whereIn('status', ['pending', 'verified'])->count();
        if ($active >= self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['file' => 'You can keep up to '.self::MAX_DOCUMENTS.' documents. Delete an old one first.']);
        }

        $ext = $this->guard->check($file, [FileGuard::PDF, FileGuard::JPG, FileGuard::PNG], self::MAX_KB, 'kyc');
        $path = $file->storeAs('kyc/'.$org->id, Str::uuid().'.'.$ext, 'local');

        $doc = $org->documents()->create([
            'type' => $type,
            'file_path' => $path,
            'original_name' => FileGuard::safeName($file->getClientOriginalName(), $ext),
            'status' => 'pending',
        ]);

        $this->audit->log('kyc_document_uploaded', $doc, after: ['type' => $type, 'size' => $file->getSize()],
            user: $user, organizationId: $org->id);

        return $doc;
    }

    public function delete(SupplierDocument $doc, User $user): void
    {
        if ($doc->status === 'verified') {
            throw ValidationException::withMessages(['file' => 'Verified documents can’t be deleted. Contact support to replace one.']);
        }

        DB::transaction(function () use ($doc, $user) {
            $this->audit->log('kyc_document_deleted', $doc, before: $doc->only(['type', 'status', 'original_name']),
                user: $user, organizationId: $doc->organization_id);
            $doc->delete();
        });

        Storage::disk('local')->delete($doc->file_path);
    }

    public function approve(SupplierDocument $doc, User $admin): void
    {
        DB::transaction(function () use ($doc, $admin) {
            $doc->update(['status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now(), 'remarks' => null]);
            $this->audit->log('kyc_document_approved', $doc, after: ['type' => $doc->type], user: $admin, organizationId: $doc->organization_id);
            $this->refreshVerification($doc->organization, $admin);
        });
    }

    public function reject(SupplierDocument $doc, User $admin, string $remarks): void
    {
        DB::transaction(function () use ($doc, $admin, $remarks) {
            $doc->update(['status' => 'rejected', 'verified_by' => $admin->id, 'verified_at' => now(), 'remarks' => $remarks]);
            $this->audit->log('kyc_document_rejected', $doc, after: ['type' => $doc->type, 'remarks' => $remarks],
                user: $admin, organizationId: $doc->organization_id);
            $this->refreshVerification($doc->organization, $admin);
        });
    }

    /** Verified badge = at least one approved GST or Udyam document. */
    public function refreshVerification(Organization $org, User $actor): void
    {
        $hasProof = $org->documents()->where('status', 'verified')->whereIn('type', self::VERIFYING_TYPES)->exists();

        if ($hasProof && ! $org->isVerified()) {
            $org->forceFill(['verified_at' => now()])->save();
            $this->audit->log('organization_verified', $org, user: $actor, organizationId: $org->id);
        } elseif (! $hasProof && $org->isVerified()) {
            $org->forceFill(['verified_at' => null])->save();
            $this->audit->log('organization_unverified', $org, user: $actor, organizationId: $org->id);
        }
    }
}
