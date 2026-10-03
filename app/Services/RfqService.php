<?php

namespace App\Services;

use App\Enums\InviteStatus;
use App\Enums\RfqStatus;
use App\Mail\RfqInvitationMail;
use App\Mail\RfqUpdateMail;
use App\Models\BuyerSupplier;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqAttachment;
use App\Models\RfqInvite;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Buyer side of an RFQ: draft → invite suppliers → publish → (deadline) → unsealed comparison.
 *
 * Sealed quotes: nothing in here returns quote amounts while the quote window is open.
 * comparison() is the only way quote prices reach a buyer, and it refuses before the deadline.
 */
class RfqService
{
    public const MAX_ITEMS = 100;
    public const MAX_ATTACHMENTS = 5;
    public const ATTACHMENT_MAX_KB = 10240;
    public const MIN_DEADLINE_MINUTES = 60;

    /** 60 in production; staging can lower it (RFQ_MIN_QUOTE_MINUTES) for quick end-to-end tests. */
    public static function minDeadlineMinutes(): int
    {
        $min = (int) config('app.rfq_min_quote_minutes', self::MIN_DEADLINE_MINUTES);

        return app()->isProduction() ? max(self::MIN_DEADLINE_MINUTES, $min) : max(1, $min);
    }
    public const MAX_DEADLINE_DAYS = 60;

    public const UNITS = ['pcs', 'nos', 'kg', 'mt', 'ton', 'ltr', 'mtr', 'sqft', 'sqm', 'box', 'roll', 'set', 'pack', 'bag', 'pair', 'job', 'lot'];
    public const PAYMENT_TERMS = ['advance' => '100% advance', 'on_delivery' => 'On delivery', 'credit_15' => '15 days credit',
        'credit_30' => '30 days credit', 'credit_45' => '45 days credit', 'credit_60' => '60 days credit', 'credit_90' => '90 days credit'];
    public const FREIGHT_TERMS = ['included' => 'Freight included in price', 'extra' => 'Freight quoted separately'];

    public function __construct(private AuditLogger $audit, private FileGuard $guard) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'delivery_location' => ['nullable', 'string', 'max:190'],
            'quote_deadline' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'bid_basis' => ['nullable', 'in:lot_total,per_item'],
            'terms.payment' => ['nullable', 'in:'.implode(',', array_keys(self::PAYMENT_TERMS))],
            'terms.freight' => ['nullable', 'in:'.implode(',', array_keys(self::FREIGHT_TERMS))],
            'terms.delivery' => ['nullable', 'string', 'max:255'],
            'terms.other' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.spec' => ['nullable', 'string', 'max:1000'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit' => ['required', 'in:'.implode(',', self::UNITS)],
            'items.*.delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.last_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    public static function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.*.name.required' => 'Every item needs a name.',
            'items.*.qty.gt' => 'Quantity must be more than 0.',
            'items.*.delivery_date.after_or_equal' => 'Delivery date can’t be in the past.',
        ];
    }

    /** Deadline typed in IST ("2026-10-05T17:00") → UTC Carbon. */
    public static function parseDeadline(?string $local): ?Carbon
    {
        return $local ? Carbon::createFromFormat('Y-m-d\TH:i', $local, config('app.display_timezone'))->utc() : null;
    }

    public function saveDraft(Organization $buyer, User $by, array $data, ?Rfq $rfq = null): Rfq
    {
        if ($rfq && ! $rfq->isDraft()) {
            throw ValidationException::withMessages(['title' => 'Only draft RFQs can be edited.']);
        }

        return DB::transaction(function () use ($buyer, $by, $data, $rfq) {
            $fields = [
                'title' => Str::squish($data['title']),
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'delivery_location' => $data['delivery_location'] ?? null,
                'quote_deadline' => self::parseDeadline($data['quote_deadline'] ?? null),
                'terms' => array_filter($data['terms'] ?? [], fn ($v) => $v !== null && $v !== ''),
                'bid_basis' => ($data['bid_basis'] ?? null) === Rfq::BASIS_PER_ITEM ? Rfq::BASIS_PER_ITEM : Rfq::BASIS_LOT,
            ];

            $creating = $rfq === null;
            $before = $creating ? null : $rfq->only(['title', 'quote_deadline']) + ['items' => $rfq->items()->count()];

            if ($creating) {
                $rfq = Rfq::create($fields + ['organization_id' => $buyer->id, 'created_by' => $by->id]);
            } else {
                $rfq->update($fields);
                $rfq->items()->delete();
            }

            foreach (array_values($data['items']) as $i => $item) {
                $rfq->items()->create([
                    'line_no' => $i + 1,
                    'name' => Str::squish($item['name']),
                    'spec' => $item['spec'] ?? null,
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'delivery_date' => $item['delivery_date'] ?? null,
                    'last_purchase_price' => $item['last_purchase_price'] ?? null,
                ]);
            }

            $this->audit->log($creating ? 'rfq_created' : 'rfq_updated', $rfq, before: $before,
                after: ['title' => $rfq->title, 'items' => count($data['items']), 'bid_basis' => $rfq->bid_basis, 'deadline' => $rfq->quote_deadline?->toIso8601String()],
                user: $by, organizationId: $buyer->id);

            return $rfq;
        });
    }

    public function addAttachment(Rfq $rfq, User $by, UploadedFile $file): RfqAttachment
    {
        if (! $rfq->isDraft() && ! $rfq->isOpenForQuotes()) {
            throw ValidationException::withMessages(['file' => 'Attachments can only be added while the RFQ is open.']);
        }
        if ($rfq->attachments()->count() >= self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['file' => 'Up to '.self::MAX_ATTACHMENTS.' attachments per RFQ.']);
        }

        $ext = $this->guard->check($file, [FileGuard::PDF, FileGuard::JPG, FileGuard::PNG, FileGuard::XLSX, FileGuard::DOCX],
            self::ATTACHMENT_MAX_KB, 'rfq_attachment');
        $path = $file->storeAs('rfq/'.$rfq->organization_id.'/'.$rfq->id, Str::uuid().'.'.$ext, 'local');

        $att = $rfq->attachments()->create([
            'file_path' => $path,
            'original_name' => FileGuard::safeName($file->getClientOriginalName(), $ext),
            'size_bytes' => $file->getSize(),
        ]);

        $this->audit->log('rfq_attachment_added', $rfq, after: ['name' => $att->original_name], user: $by, organizationId: $rfq->organization_id);

        return $att;
    }

    public function removeAttachment(RfqAttachment $att, User $by): void
    {
        $rfq = $att->rfq;
        if (! $rfq->isDraft()) {
            throw ValidationException::withMessages(['file' => 'Attachments can’t be removed after publishing, as suppliers may already rely on them.']);
        }

        $this->audit->log('rfq_attachment_removed', $rfq, before: ['name' => $att->original_name], user: $by, organizationId: $rfq->organization_id);
        $att->delete();
        Storage::disk('local')->delete($att->file_path);
    }

    /**
     * Invite suppliers from the buyer's own list. Blocked or foreign entries are ignored.
     * After publishing, new invites are sent straight away.
     *
     * @param  list<int>  $listIds  buyer_supplier_lists ids
     */
    public function invite(Rfq $rfq, User $by, array $listIds): int
    {
        if (! $rfq->isDraft() && ! $rfq->isOpenForQuotes()) {
            throw ValidationException::withMessages(['suppliers' => 'Suppliers can only be invited while the RFQ is open.']);
        }

        $entries = BuyerSupplier::where('buyer_org_id', $rfq->organization_id)
            ->where('status', 'active')
            ->whereIn('id', $listIds)
            ->get();

        $created = collect();
        DB::transaction(function () use ($rfq, $entries, &$created) {
            foreach ($entries as $entry) {
                $already = $rfq->invites()
                    ->where(fn ($q) => $q->where('buyer_supplier_list_id', $entry->id)
                        ->when($entry->supplier_org_id, fn ($q) => $q->orWhere('supplier_org_id', $entry->supplier_org_id)))
                    ->exists();
                if ($already) {
                    continue;
                }
                $created->push($rfq->invites()->create([
                    'buyer_supplier_list_id' => $entry->id,
                    'supplier_org_id' => $entry->supplier_org_id,
                    'status' => InviteStatus::Invited,
                ]));
            }
        });

        if ($created->isNotEmpty()) {
            $this->audit->log('rfq_suppliers_invited', $rfq, after: ['count' => $created->count(), 'list_ids' => $entries->pluck('id')->all()],
                user: $by, organizationId: $rfq->organization_id);

            if ($rfq->isOpenForQuotes()) {
                $this->sendInvitations($rfq, $created);
            }
        }

        return $created->count();
    }

    public function removeInvite(RfqInvite $invite, User $by): void
    {
        $rfq = $invite->rfq;
        if (! $rfq->isDraft()) {
            throw ValidationException::withMessages(['suppliers' => 'Invites can’t be withdrawn after publishing.']);
        }

        $this->audit->log('rfq_invite_removed', $rfq, before: ['list_id' => $invite->buyer_supplier_list_id], user: $by, organizationId: $rfq->organization_id);
        $invite->delete();
    }

    public function publish(Rfq $rfq, User $by): void
    {
        $errors = [];
        if (! $rfq->isDraft()) {
            $errors['rfq'] = 'This RFQ is already published.';
        }
        if ($rfq->items()->count() === 0) {
            $errors['items'] = 'Add at least one item.';
        }
        if (! $rfq->quote_deadline) {
            $errors['quote_deadline'] = 'Set a deadline for quotes.';
        } elseif ($rfq->quote_deadline->lt(now()->addMinutes($min = self::minDeadlineMinutes()))) {
            $errors['quote_deadline'] = $min >= 60 && $min % 60 === 0
                ? 'Give suppliers at least '.($min / 60).' '.str('hour')->plural($min / 60).' to quote.'
                : "Give suppliers at least {$min} minutes to quote.";
        } elseif ($rfq->quote_deadline->gt(now()->addDays(self::MAX_DEADLINE_DAYS))) {
            $errors['quote_deadline'] = 'The deadline can be at most '.self::MAX_DEADLINE_DAYS.' days away.';
        }
        if ($rfq->invites()->count() === 0) {
            $errors['suppliers'] = 'Invite at least one supplier.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($rfq, $by) {
            $rfq->update(['status' => RfqStatus::Published, 'published_at' => now()]);
            $this->audit->log('rfq_published', $rfq, after: [
                'deadline' => $rfq->quote_deadline->toIso8601String(), 'invites' => $rfq->invites()->count(),
            ], user: $by, organizationId: $rfq->organization_id);
        });

        $this->sendInvitations($rfq, $rfq->invites()->get());
    }

    public function extendDeadline(Rfq $rfq, User $by, Carbon $new): void
    {
        if ($rfq->status !== RfqStatus::Published) {
            throw ValidationException::withMessages(['quote_deadline' => 'Only published RFQs can be extended.']);
        }
        if ($new->lte($rfq->quote_deadline) || $new->lt(now()->addMinutes(30))) {
            throw ValidationException::withMessages(['quote_deadline' => 'The new deadline must be later than the current one and at least 30 minutes from now.']);
        }
        if ($new->gt(now()->addDays(self::MAX_DEADLINE_DAYS))) {
            throw ValidationException::withMessages(['quote_deadline' => 'The deadline can be at most '.self::MAX_DEADLINE_DAYS.' days away.']);
        }

        $old = $rfq->quote_deadline;
        $rfq->update(['quote_deadline' => $new]);
        $this->audit->log('rfq_deadline_extended', $rfq, before: ['deadline' => $old->toIso8601String()],
            after: ['deadline' => $new->toIso8601String()], user: $by, organizationId: $rfq->organization_id);

        $this->notifySuppliers($rfq, 'extended');
    }

    public function cancel(Rfq $rfq, User $by, string $reason): void
    {
        if ($rfq->isCancelled() || ! in_array($rfq->status, [RfqStatus::Draft, RfqStatus::Published], true)) {
            throw ValidationException::withMessages(['reason' => 'This RFQ can’t be cancelled now.']);
        }

        $wasPublished = $rfq->status === RfqStatus::Published;
        $rfq->update(['status' => RfqStatus::Cancelled]);
        $this->audit->log('rfq_cancelled', $rfq, after: ['reason' => $reason], user: $by, organizationId: $rfq->organization_id);
        app(PurchaseRequestService::class)->release($rfq, $by); // its purchase requests go back to the purchase team

        if ($wasPublished) {
            $this->notifySuppliers($rfq, 'cancelled', $reason);
        }
    }

    /**
     * Unsealed quote comparison. Refuses while quotes are sealed.
     *
     * @return Collection<int, array{quote: Quote, rank: int, basic: float, gst: float, freight: float, landed: float, lines: array}>
     */
    public function comparison(Rfq $rfq): Collection
    {
        if (! $rfq->quotesAreUnsealed()) {
            throw new \LogicException('Quotes are sealed until the deadline.');
        }

        $items = $rfq->items()->get()->keyBy('id');
        $quotes = Quote::with(['items', 'supplier'])->where('rfq_id', $rfq->id)->whereNotNull('submitted_at')->get();

        return $quotes->map(function (Quote $q) use ($items) {
            $basic = $gst = $freight = 0.0;
            $lines = [];
            foreach ($q->items as $qi) {
                $item = $items[$qi->rfq_item_id] ?? null;
                if (! $item) {
                    continue;
                }
                $lineBasic = (float) $qi->unit_price * (float) $item->qty;
                $lineGst = $lineBasic * (float) $qi->gst_rate / 100;
                $basic += $lineBasic;
                $gst += $lineGst;
                $freight += (float) $qi->freight;
                $lines[$qi->rfq_item_id] = ['unit_price' => (float) $qi->unit_price, 'gst_rate' => (float) $qi->gst_rate, 'freight' => (float) $qi->freight];
            }

            return [
                'quote' => $q,
                'basic' => Money::round($basic),
                'gst' => Money::round($gst),
                'freight' => Money::round($freight),
                'landed' => Money::round($basic + $gst + $freight),
                'lines' => $lines,
            ];
        })
            ->sortBy([['landed', 'asc'], [fn ($a, $b) => $a['quote']->submitted_at <=> $b['quote']->submitted_at]])
            ->values()
            ->map(fn ($row, $i) => $row + ['rank' => $i + 1]);
    }

    /** Invite URL a buyer can share by WhatsApp (bearer link, bound to one supplier on first claim). */
    public static function inviteUrl(RfqInvite $invite): string
    {
        return route('invites.show', $invite->token);
    }

    private function sendInvitations(Rfq $rfq, Collection $invites): void
    {
        foreach ($invites as $invite) {
            $email = $this->recipientEmail($invite);
            if ($email) {
                Mail::to($email)->queue(new RfqInvitationMail($invite));
            }
            Notifier::toOrg($invite->supplier_org_id, 'sourcing', 'New RFQ from '.$rfq->organization?->name,
                "{$rfq->ref_no} · {$rfq->title}. Quotes close ".$rfq->quote_deadline?->ist()->format('d M, h:i A').' IST.', route('supplier.rfqs.show', $invite->id));
            $invite->forceFill(['last_sent_at' => now()])->save();
        }
    }

    private function notifySuppliers(Rfq $rfq, string $kind, ?string $reason = null): void
    {
        $rfq->invites()->where('status', '!=', InviteStatus::Declined->value)->get()
            ->each(function (RfqInvite $invite) use ($rfq, $kind, $reason) {
                if ($email = $this->recipientEmail($invite)) {
                    Mail::to($email)->queue(new RfqUpdateMail($invite, $kind, $reason));
                }
                Notifier::toOrg($invite->supplier_org_id, 'sourcing',
                    ($kind === 'cancelled' ? 'RFQ cancelled: ' : 'Deadline extended: ').$rfq->ref_no,
                    $kind === 'cancelled' ? $rfq->title.($reason ? ". Reason: {$reason}" : '.') : "{$rfq->title}. Quotes now close ".$rfq->quote_deadline?->ist()->format('d M, h:i A').' IST.',
                    route('supplier.rfqs.show', $invite->id));
            });
    }

    /** Where a supplier's RFQ emails go: the contact in the buyer's list, else the company email. */
    public static function recipientEmail(RfqInvite $invite): ?string
    {
        $invite->loadMissing(['listEntry', 'supplier']);

        return $invite->listEntry?->contact_email ?: $invite->supplier?->email;
    }
}
