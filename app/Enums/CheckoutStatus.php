<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckoutStatus: string
{
    case Creating = 'creating';
    case AwaitingPayment = 'awaiting_payment';
    case CreationUnknown = 'creation_unknown';
    case CreationFailed = 'creation_failed';
    case Paid = 'paid';
    case Expired = 'expired';
}
