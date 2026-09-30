<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RfqInvite extends Model
{
    protected $fillable = [
        'rfq_id', 'supplier_org_id', 'buyer_supplier_list_id', 'token', 'status',
        'accepted_terms_at', 'declined_at', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'accepted_terms_at' => 'datetime',
            'declined_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RfqInvite $invite) {
            $invite->token ??= Str::random(48);
        });
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class)->withoutGlobalScope('organization');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function listEntry(): BelongsTo
    {
        return $this->belongsTo(BuyerSupplier::class, 'buyer_supplier_list_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === InviteStatus::Accepted;
    }
}
