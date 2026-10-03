<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agreed rate per item with one supplier for a period. "Expired" is never stored: it is
 * worked out from valid_to (IST), so a contract can't stay "active" by mistake.
 */
class RateContract extends Model
{
    use BelongsToOrganization;

    public const ACTIVE = 'active';
    public const CANCELLED = 'cancelled';
    public const MAX_ITEMS = 100;

    protected $fillable = [
        'organization_id', 'rc_number', 'supplier_org_id', 'title', 'valid_from', 'valid_to', 'items', 'terms', 'status', 'award_id',
        'created_by', 'supplier_accepted_at', 'supplier_accepted_by', 'cancelled_at', 'cancel_reason', 'expiry_alerted',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date', 'valid_to' => 'date', 'items' => 'array',
            'supplier_accepted_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** Stored as plain dates (Y-m-d) on every database, so date comparisons can use the index. */
    protected function validFrom(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($v) => \Illuminate\Support\Carbon::parse($v)->toDateString());
    }

    protected function validTo(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($v) => \Illuminate\Support\Carbon::parse($v)->toDateString());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class)->withoutGlobalScopes();
    }

    public static function today(): string
    {
        return now(config('app.display_timezone'))->toDateString();
    }

    /** Running today (not cancelled, inside its dates). */
    public function scopeInForce(Builder $q): Builder
    {
        $today = self::today();

        return $q->where('status', self::ACTIVE)->where('valid_from', '<=', $today)->where('valid_to', '>=', $today);
    }

    /** active | upcoming | expired | cancelled */
    public function state(): string
    {
        if ($this->status === self::CANCELLED) {
            return 'cancelled';
        }
        $today = self::today();

        return match (true) {
            $this->valid_to->toDateString() < $today => 'expired',
            $this->valid_from->toDateString() > $today => 'upcoming',
            default => 'active',
        };
    }

    public function daysLeft(): int
    {
        return (int) \Illuminate\Support\Carbon::parse(self::today())->diffInDays($this->valid_to->copy()->startOfDay(), false);
    }
}
