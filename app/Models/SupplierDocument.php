<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDocument extends Model
{
    public const TYPES = ['gst', 'pan', 'udyam', 'other'];

    protected $fillable = [
        'organization_id', 'type', 'file_path', 'original_name', 'verified_by', 'verified_at', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
