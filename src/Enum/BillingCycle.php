<?php

declare(strict_types=1);

namespace App\Enum;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
