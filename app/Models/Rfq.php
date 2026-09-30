<?php

namespace App\Models;

use App\Enums\RfqStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rfq extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'organization_id', 'created_by', 'ref_no', 'title', 'description', 'category_id', 'bid_basis',
        'currency', 'terms', 'delivery_location', 'status', 'quote_deadline', 'published_at',
        'approved_by', 'approved_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'bid_basis' => 'lot_total',
        'currency' => 'INR',
    ];

    protected function casts(): array
    {
        return [
            'status' => RfqStatus::class,
            'terms' => 'array',
            'quote_deadline' => 'datetime',
            'published_at' => 'datetime',
            'quotes_opened_notified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === RfqStatus::Draft;
    }

    /** Published and the sealed-quote deadline hasn't passed (server time). */
    public function isOpenForQuotes(): bool
    {
        return $this->status === RfqStatus::Published
            && $this->quote_deadline !== null
            && $this->quote_deadline->isFuture();
    }

    /** Sealed quotes may be opened only after the deadline. */
    public function quotesAreUnsealed(): bool
    {
        return $this->status !== RfqStatus::Draft
            && $this->status !== RfqStatus::Cancelled
            && $this->quote_deadline !== null
            && ! $this->quote_deadline->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status === RfqStatus::Cancelled;
    }

    /** For badges: draft | open | closed | auction | evaluating | awarded | cancelled */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isDraft() => 'draft',
            $this->isCancelled() => 'cancelled',
            $this->status === RfqStatus::Auction => 'auction',
            $this->status === RfqStatus::Evaluating => 'evaluating',
            $this->status === RfqStatus::Awarded => 'awarded',
            $this->isOpenForQuotes() => 'open',
            default => 'closed',
        };
    }

    protected static function booted(): void
    {
        static::creating(function (Rfq $rfq) {
            if (empty($rfq->ref_no)) {
                $rfq->ref_no = static::nextRefNo($rfq->organization_id);
            }
        });
    }

    /** RFQ-2026-0001, sequential per buyer org per year. */
    public static function nextRefNo(int $organizationId): string
    {
        $year = now()->year;
        $count = static::withoutGlobalScopes()
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->count();

        return sprintf('RFQ-%d-%04d', $year, $count + 1);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('line_no');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RfqAttachment::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(RfqInvite::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    public function auction(): HasOne
    {
        return $this->hasOne(Auction::class)->latestOfMany();
    }

    public function awards(): HasMany
    {
        return $this->hasMany(Award::class);
    }
}
