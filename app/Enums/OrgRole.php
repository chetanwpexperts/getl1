<?php

namespace App\Enums;

enum OrgRole: string
{
    case BuyerAdmin = 'buyer_admin';
    case BuyerUser = 'buyer_user';
    case Approver = 'approver';
    case Requester = 'requester';
    case SupplierUser = 'supplier_user';

    public function label(): string
    {
        return match ($this) {
            self::BuyerAdmin => 'Admin',
            self::BuyerUser => 'Buyer',
            self::Approver => 'Approver',
            self::Requester => 'Requester',
            self::SupplierUser => 'Supplier',
        };
    }

    /** Roles that may exist inside an organization of the given type. */
    public static function forType(OrganizationType $type): array
    {
        return $type === OrganizationType::Buyer
            ? [self::BuyerAdmin, self::BuyerUser, self::Approver, self::Requester]
            : [self::SupplierUser];
    }
}
