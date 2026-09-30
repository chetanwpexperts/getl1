<?php

namespace App\Enums;

enum AwardStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PoSent = 'po_sent';
}
