<?php

namespace App\Enums;

enum BusinessAbility: string
{
    case ManageBusiness = 'manage_business';
    case ManageCatalog = 'manage_catalog';
    case ManageOutlet = 'manage_outlet';
    case ManageStock = 'manage_stock';
    case CreateOrder = 'create_order';
    case ManagePayment = 'manage_payment';
    case ViewOrders = 'view_orders';
    case CancelOrder = 'cancel_order';
    case ViewKitchen = 'view_kitchen';
    case ViewAnalytics = 'view_analytics';
}
