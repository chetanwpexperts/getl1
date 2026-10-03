<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One level an award must pass. Fixed when the award is made. */
class AwardApprovalStep extends Model
{
    use BelongsToOrganization;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = ['organization_id', 'award_id', 'group_key', 'position', 'name', 'why', 'approver_user_id', 'status', 'decided_by', 'decided_at', 'note'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
