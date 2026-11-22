<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceKind: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case Adjustment = 'adjustment';
}
