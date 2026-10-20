<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case Adjustment = 'adjustment';
    case Sale = 'sale';
}
