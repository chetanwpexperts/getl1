<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type', 'name', 'slug', 'gstin', 'pan', 'udyam_no', 'email', 'phone',
        'address', 'city', 'state', 'pincode', 'locale', 'verified_at', 'status',
        'award_approval_limit', 'po_terms', 'auction_credits', 'ai_credits',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'verified_at' => 'datetime',
            'award_approval_limit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $org) {
            if (empty($org->slug)) {
                $base = Str::slug($org->name) ?: 'org';
                $slug = $base;
                $i = 1;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }
                $org->slug = $slug;
            }
        });
    }

    public function isBuyer(): bool
    {
        return $this->type === OrganizationType::Buyer;
    }

    public function isSupplier(): bool
    {
        return $this->type === OrganizationType::Supplier;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'org_user')
            ->withPivot(['role', 'is_owner'])
            ->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /** A paid plan or a running trial right now (otherwise the company is on Free). */
    public function hasUsableSubscription(): bool
    {
        return app(\App\Services\Billing\PlanService::class)->liveSubscription($this) !== null;
    }

    // Supplier side
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'supplier_categories')->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(RfqInvite::class, 'supplier_org_id');
    }

    // Buyer side
    public function supplierList(): HasMany
    {
        return $this->hasMany(BuyerSupplier::class, 'buyer_org_id');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }
}
