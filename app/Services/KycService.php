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

    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /** Types that, once approved, verify the business. */
    private const VERIFYING_TYPES = ['gst', 'udyam'];

    public function __construct(private AuditLogger $audit) {}

    public function store(Organization $org, User $user, string $type, UploadedFile $file): SupplierDocument
    {
        $active = $org->documents()->whereIn('status', ['pending', 'verified'])->count();
        if ($active >= self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['file' => 'You can keep up to '.self::MAX_DOCUMENTS.' documents. Delete an old one first.']);
        }

        $ext = $this->inspect($file);
        $path = $file->storeAs('kyc/'.$org->id, Str::uuid().'.'.$ext, 'local');

        $doc = $org->documents()->create([
            'type' => $type,
            'file_path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName(), $ext),
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

    /**
     * Returns the extension to store under, based on the file's real content.
     */
    private function inspect(UploadedFile $file): string
    {
        $fail = function (string $reason) use ($file) {
            SecurityLog::warning('upload_rejected', [
                'kind' => 'kyc', 'reason' => $reason,
                'client_name' => substr($file->getClientOriginalName(), 0, 120),
                'size' => $file->getSize(),
            ]);

            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPG or PNG file (max 5 MB).']);
        };

        if (! $file->isValid() || $file->getSize() === 0 || $file->getSize() > self::MAX_KB * 1024) {
            $fail('invalid_or_size');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        if (! isset(self::ALLOWED[$mime])) {
            $fail('mime:'.$mime);
        }

        if ($mime === 'application/pdf') {
            $bytes = (string) file_get_contents($file->getRealPath(), false, null, 0, self::MAX_KB * 1024);
            if (! str_starts_with($bytes, '%PDF-')) {
                $fail('pdf_header');
            }
            // Refuse PDFs with active content. (Content inside compressed object streams isn't
            // visible to this scan; documents are only ever downloaded, never rendered in-app.)
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b#', $bytes)) {
                $fail('pdf_active_content');
            }
        } else {
            $info = @getimagesize($file->getRealPath());
            if ($info === false || $info[0] < 50 || $info[1] < 50 || $info[0] > 10000 || $info[1] > 10000) {
                $fail('image_unreadable');
            }
        }

        return self::ALLOWED[$mime];
    }

    private function safeName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN _.-]+/u', '', $base) ?: 'document';

        return Str::limit(trim($base), 100, '').'.'.$ext;
    }
}
