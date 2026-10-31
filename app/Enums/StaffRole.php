<?php

namespace App\Enums;

enum StaffRole: string
{
    case BusinessAdmin = 'business_admin';
    case Cashier = 'cashier';
    case Kitchen = 'kitchen';
}
