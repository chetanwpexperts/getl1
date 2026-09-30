<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes a buyer-side model to the current organization.
 *
 * - Every query is filtered by organization_id when a current org is set.
 * - organization_id is filled automatically on create.
 * - Use Model::withoutGlobalScope('organization') for platform-admin or
 *   supplier-side reads (a supplier reading an RFQ it was invited to).
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $current = app(CurrentOrganization::class);

            if ($current->has()) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $current->id());
            }
        });

        static::creating(function ($model) {
            $current = app(CurrentOrganization::class);

            if (empty($model->organization_id) && $current->has()) {
                $model->organization_id = $current->id();
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}
