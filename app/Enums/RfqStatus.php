<?php

namespace App\Enums;

enum RfqStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Published = 'published';
    case Quoting = 'quoting';
    case Auction = 'auction';
    case Evaluating = 'evaluating';
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval], true);
    }
}
