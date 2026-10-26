<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
