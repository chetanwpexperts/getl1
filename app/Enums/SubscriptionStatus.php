<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';   // created, waiting for the first payment
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function allowsUse(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }
}
