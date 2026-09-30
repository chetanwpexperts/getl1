<?php

namespace App\Enums;

enum AuctionStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
