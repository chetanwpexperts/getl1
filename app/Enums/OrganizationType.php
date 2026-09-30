<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Buyer = 'buyer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Supplier => 'Supplier',
        };
    }
}
