<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'organization_id', 'recipient_phone', 'supplier_org_id', 'template', 'direction', 'body',
        'related_type', 'related_id', 'provider_message_id', 'status', 'error',
    ];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }
}
