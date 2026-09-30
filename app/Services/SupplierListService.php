<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\User;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A buyer's private supplier list. Every read/write here is scoped to one buyer org.
 */
class SupplierListService
{
    public const MAX_PER_BUYER = 2000;

    /** Header aliases accepted in imports (lower-cased, spaces/underscores ignored). */
    private const HEADERS = [
        'company_name' => ['company', 'companyname', 'supplier', 'suppliername', 'firm', 'firmname', 'vendor', 'vendorname', 'name'],
        'contact_name' => ['contact', 'contactname', 'contactperson', 'person', 'owner'],
        'contact_email' => ['email', 'emailid', 'mail', 'emailaddress'],
        'contact_phone' => ['phone', 'mobile', 'mobileno', 'phoneno', 'whatsapp', 'contactno', 'number'],
        'tag' => ['tag', 'category', 'group', 'type'],
        'notes' => ['notes', 'note', 'remarks', 'comment'],
    ];

    public function __construct(private AuditLogger $audit) {}

    public static function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'required_without:contact_phone', 'email:rfc', 'max:190'],
            'contact_phone' => ['nullable', 'required_without:contact_email', 'regex:/^[6-9]\d{9}$/'],
            'tag' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a 10-digit Indian mobile number.',
            'contact_email.required_without' => 'Add an email or a mobile number.',
            'contact_phone.required_without' => 'Add a mobile number or an email.',
        ];
    }

    /** "+91 98765-43210" / "098765 43210" → "9876543210" */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits === '' ? null : $digits;
    }

    public static function normalize(array $data): array
    {
        $data['contact_email'] = isset($data['contact_email']) && $data['contact_email'] !== '' ? Str::lower(trim($data['contact_email'])) : null;
        $data['contact_phone'] = self::normalizePhone($data['contact_phone'] ?? null);
        foreach (['company_name', 'contact_name', 'tag', 'notes'] as $f) {
            $data[$f] = isset($data[$f]) && trim((string) $data[$f]) !== '' ? Str::squish((string) $data[$f]) : null;
        }
        if ($data['tag']) {
            $data['tag'] = Str::lower($data['tag']);
        }

        return $data;
    }

    /** Another entry in this buyer's list already using this email or phone? */
    public function findDuplicate(Organization $buyer, ?string $email, ?string $phone, ?int $ignoreId = null): ?BuyerSupplier
    {
        if (! $email && ! $phone) {
            return null;
        }

        return BuyerSupplier::where('buyer_org_id', $buyer->id)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->orWhere('contact_email', $email);
                }
                if ($phone) {
                    $q->orWhere('contact_phone', $phone);
                }
            })
            ->first();
    }

    /** Registered supplier company whose user owns this email/phone. Never links to buyer orgs. */
    public function findRegisteredSupplier(Organization $buyer, ?string $email, ?string $phone): ?Organization
    {
        if (! $email && ! $phone) {
            return null;
        }

        $user = User::query()
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->first();

        return $user?->organizations()
            ->where('type', OrganizationType::Supplier->value)
            ->whereKeyNot($buyer->id)
            ->first();
    }

    public function create(Organization $buyer, User $by, array $data): BuyerSupplier
    {
        $data = self::normalize($data);

        if (BuyerSupplier::where('buyer_org_id', $buyer->id)->count() >= self::MAX_PER_BUYER) {
            throw new RuntimeException('Supplier list limit reached ('.self::MAX_PER_BUYER.').');
        }

        $entry = BuyerSupplier::create($data + [
            'buyer_org_id' => $buyer->id,
            'supplier_org_id' => $this->linkableSupplierId($buyer, $data),
            'status' => 'active',
        ]);

        $this->audit->log('supplier_list_added', $entry, after: $entry->only(['company_name', 'contact_email', 'contact_phone', 'supplier_org_id']),
            user: $by, organizationId: $buyer->id);

        return $entry;
    }

    public function update(BuyerSupplier $entry, User $by, array $data): BuyerSupplier
    {
        $data = self::normalize($data);
        $before = $entry->only(array_keys($data));

        $entry->fill($data);
        if (! $entry->supplier_org_id && $entry->isDirty(['contact_email', 'contact_phone'])) {
            $entry->supplier_org_id = $this->linkableSupplierId($entry->buyer, $data);
        }
        $entry->save();

        $this->audit->log('supplier_list_updated', $entry, before: $before, after: $entry->only(array_keys($data)),
            user: $by, organizationId: $entry->buyer_org_id);

        return $entry;
    }

    public function setStatus(BuyerSupplier $entry, User $by, string $status): void
    {
        $before = $entry->status;
        $entry->update(['status' => $status]);
        $this->audit->log($status === 'blocked' ? 'supplier_list_blocked' : 'supplier_list_unblocked', $entry,
            before: ['status' => $before], after: ['status' => $status], user: $by, organizationId: $entry->buyer_org_id);
    }

    public function remove(BuyerSupplier $entry, User $by): void
    {
        DB::transaction(function () use ($entry, $by) {
            $this->audit->log('supplier_list_removed', $entry,
                before: $entry->only(['company_name', 'contact_email', 'contact_phone', 'supplier_org_id', 'status']),
                user: $by, organizationId: $entry->buyer_org_id);
            $entry->delete();
        });
    }

    /**
     * @return array{created:int, skipped:int, errors:list<string>}
     */
    public function import(Organization $buyer, User $by, string $path, string $extension): array
    {
        $rows = SpreadsheetReader::read($path, $extension);
        if (count($rows) < 2) {
            throw new RuntimeException('The file has no supplier rows under the header.');
        }

        $map = $this->mapHeader(array_shift($rows));
        if (! isset($map['company_name'])) {
            throw new RuntimeException('Could not find a "Company name" column. Use the template.');
        }
        if (! isset($map['contact_email']) && ! isset($map['contact_phone'])) {
            throw new RuntimeException('Add an "Email" or "Mobile" column. Use the template.');
        }

        $existing = BuyerSupplier::where('buyer_org_id', $buyer->id)->count();
        $room = self::MAX_PER_BUYER - $existing;
        $seenEmails = [];
        $seenPhones = [];
        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $map, $buyer, $by, &$created, &$skipped, &$errors, &$seenEmails, &$seenPhones, $room) {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // spreadsheet row number (header is row 1)
                $data = [];
                foreach ($map as $field => $col) {
                    $data[$field] = $row[$col] ?? null;
                }
                if (implode('', array_map('strval', $data)) === '') {
                    continue; // empty row
                }

                $data = self::normalize($data);
                $v = Validator::make($data, self::rules(), self::messages());
                if ($v->fails()) {
                    $errors[] = "Row {$line}: ".$v->errors()->first();
                    continue;
                }

                $email = $data['contact_email'];
                $phone = $data['contact_phone'];
                if (($email && isset($seenEmails[$email])) || ($phone && isset($seenPhones[$phone]))
                    || $this->findDuplicate($buyer, $email, $phone)) {
                    $skipped++;
                    continue;
                }

                if ($created >= $room) {
                    $errors[] = "Row {$line}: supplier list limit reached (".self::MAX_PER_BUYER.').';
                    break;
                }

                BuyerSupplier::create($data + [
                    'buyer_org_id' => $buyer->id,
                    'supplier_org_id' => $this->linkableSupplierId($buyer, $data),
                    'status' => 'active',
                ]);
                $created++;
                if ($email) {
                    $seenEmails[$email] = true;
                }
                if ($phone) {
                    $seenPhones[$phone] = true;
                }
            }
        });

        $this->audit->log('supplier_list_imported', $buyer,
            after: ['created' => $created, 'skipped' => $skipped, 'errors' => count($errors), 'rows' => count($rows)],
            user: $by, organizationId: $buyer->id);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function linkableSupplierId(Organization $buyer, array $data): ?int
    {
        $supplier = $this->findRegisteredSupplier($buyer, $data['contact_email'] ?? null, $data['contact_phone'] ?? null);
        if (! $supplier) {
            return null;
        }

        // Don't link the same registered supplier twice in one buyer's list.
        $alreadyLinked = BuyerSupplier::where('buyer_org_id', $buyer->id)->where('supplier_org_id', $supplier->id)->exists();

        return $alreadyLinked ? null : $supplier->id;
    }

    /** @return array<string,int> field => column index */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $label) {
            $key = preg_replace('/[\s_\-.]+/', '', Str::lower((string) $label));
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($key, $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }
}
