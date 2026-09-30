<?php

namespace App\Enums;

enum InviteStatus: string
{
    case Invited = 'invited';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
