<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Expected, recoverable failure of a stock operation.
 *
 * Callers (e.g. the Task #008 UI) can catch this class and read $reason.
 */
class StockException extends RuntimeException
{
    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_BUSINESS_MISMATCH = 'business_mismatch';

    public const REASON_INVALID_QUANTITY = 'invalid_quantity';

    public const REASON_ZERO_QUANTITY = 'zero_quantity';

    public const REASON_QUANTITY_OVERFLOW = 'quantity_overflow';

    public const REASON_INSUFFICIENT_STOCK = 'insufficient_stock';

    public const REASON_DUPLICATE_OPENING = 'duplicate_opening';

    public const REASON_INVALID_NOTE = 'invalid_note';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(self::REASON_UNAUTHORIZED, 'You do not own the business of this outlet.');
    }

    public static function notFound(): self
    {
        return new self(self::REASON_NOT_FOUND, 'The outlet or product variant no longer exists.');
    }

    public static function businessMismatch(): self
    {
        return new self(self::REASON_BUSINESS_MISMATCH, 'The outlet and the product variant belong to different businesses.');
    }

    public static function invalidQuantity(string $message): self
    {
        return new self(self::REASON_INVALID_QUANTITY, $message);
    }

    public static function zeroQuantity(): self
    {
        return new self(self::REASON_ZERO_QUANTITY, 'The quantity must not be zero.');
    }

    public static function quantityOverflow(): self
    {
        return new self(self::REASON_QUANTITY_OVERFLOW, 'The resulting stock balance exceeds the maximum supported quantity.');
    }

    public static function insufficientStock(string $current, string $delta): self
    {
        return new self(
            self::REASON_INSUFFICIENT_STOCK,
            "Insufficient stock: a change of {$delta} would make the current balance of {$current} negative."
        );
    }

    public static function duplicateOpening(): self
    {
        return new self(self::REASON_DUPLICATE_OPENING, 'Opening stock has already been recorded for this outlet and product variant.');
    }

    public static function invalidNote(): self
    {
        return new self(self::REASON_INVALID_NOTE, 'The note must not be longer than 255 characters.');
    }
}
