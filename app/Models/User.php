<?php

namespace App\Models;

use App\Enums\OrgRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'current_organization_id',
        'last_login_at',
        'invited_at',
        'invited_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'locked_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'org_user')
            ->withPivot(['role', 'is_owner'])
            ->withTimestamps();
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()->whereKey($id)->exists();
    }

    public function roleIn(Organization|int $organization): ?OrgRole
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $role = $this->organizations()->whereKey($id)->first()?->pivot?->role;

        return $role ? OrgRole::from($role) : null;
    }

    /** @param  OrgRole|string  ...$roles */
    public function hasRoleIn(Organization|int $organization, OrgRole|string ...$roles): bool
    {
        $role = $this->roleIn($organization);

        if (! $role) {
            return false;
        }

        foreach ($roles as $r) {
            $r = $r instanceof OrgRole ? $r : OrgRole::from($r);
            if ($r === $role) {
                return true;
            }
        }

        return false;
    }

    public function switchOrganization(Organization $organization): void
    {
        abort_unless($this->belongsToOrganization($organization), 403);

        $this->forceFill(['current_organization_id' => $organization->id])->save();
    }
}
