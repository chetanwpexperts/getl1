<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiJob extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'user_id', 'type', 'input_ref_type', 'input_ref_id', 'input_file_path', 'input_text',
        'output', 'status', 'paid_with_credit', 'error', 'model', 'tokens_in', 'tokens_out', 'cost_inr', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'paid_with_credit' => 'boolean',
            'cost_inr' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function inputRef(): MorphTo
    {
        return $this->morphTo('input_ref');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
