<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of adjustment a pricing quote can contain.
 *
 * String-backed because these values cross a service boundary: they are read
 * from the pricing service's JSON and written into API responses. An integer
 * enum would make both sides depend on declaration order.
 */
enum PriceLineType: string
{
    case Coupon = 'coupon';
    case Tax = 'tax';
}
