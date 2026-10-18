<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Expected, recoverable rejection of an order operation.
 *
 * Only domain rejections are thrown as OrderException; callers (for example a
 * future UI) can catch it and read $reason. Database errors such as integrity
 * violations, deadlocks and lock timeouts are never wrapped: they propagate
 * unchanged so that unexpected failures stay visible.
 */
class OrderException extends RuntimeException
{
    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_BUSINESS_MISMATCH = 'business_mismatch';

    public const REASON_INVALID_QUANTITY = 'invalid_quantity';

    public const REASON_QUANTITY_OVERFLOW = 'quantity_overflow';

    public const REASON_ORDER_NOT_OPEN = 'order_not_open';

    public const REASON_EMPTY_ORDER = 'empty_order';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(self::REASON_UNAUTHORIZED, 'You do not own the business this operation refers to.');
    }

    public static function notFound(): self
    {
        return new self(self::REASON_NOT_FOUND, 'The business, outlet, order or product variant no longer exists.');
    }

    public static function businessMismatch(): self
    {
        return new self(self::REASON_BUSINESS_MISMATCH, 'The outlet or product variant belongs to a different business than the order.');
    }

    public static function invalidQuantity(string $message): self
    {
        return new self(self::REASON_INVALID_QUANTITY, $message);
    }

    public static function quantityOverflow(): self
    {
        return new self(self::REASON_QUANTITY_OVERFLOW, 'The quantity exceeds the maximum of 1,000,000 per product variant in an order.');
    }

    public static function orderNotOpen(): self
    {
        return new self(self::REASON_ORDER_NOT_OPEN, 'The order is not open, so it can no longer change.');
    }

    public static function emptyOrder(): self
    {
        return new self(self::REASON_EMPTY_ORDER, 'An order without items cannot be completed.');
    }
}
