<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Single place that writes audit_logs. Call from services and controllers for
 * every state change that matters for trust: publish, invite, quote, bid, award, approve.
 */
class AuditLogger
{
    public function __construct(private CurrentOrganization $current) {}

    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?User $user = null,
        ?int $organizationId = null,
    ): AuditLog {
        $user ??= Auth::user();

        return AuditLog::create([
            'organization_id' => $organizationId ?? $this->current->id(),
            'user_id' => $user?->id,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
