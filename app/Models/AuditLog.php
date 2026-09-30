<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Append-only audit trail. Write through App\Services\AuditLogger. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'organization_id', 'user_id', 'auditable_type', 'auditable_id', 'action', 'before', 'after', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
