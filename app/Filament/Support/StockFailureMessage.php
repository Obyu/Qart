<?php

namespace App\Filament\Support;

use App\Exceptions\StockException;

/**
 * Turns a StockException into text that is safe to show in the UI.
 *
 * Only the reason code is read. The exception's own message can carry balances
 * and variant ids, and for ownership problems it must not confirm that a
 * record exists, so it is never shown.
 */
final class StockFailureMessage
{
    public const UNAVAILABLE = 'That outlet or product variant is not available. Nothing was changed.';

    public const FALLBACK = 'The stock could not be changed. Nothing was changed.';

    public static function describe(StockException $exception): string
    {
        return match ($exception->reason) {
            StockException::REASON_UNAUTHORIZED,
            StockException::REASON_NOT_FOUND,
            StockException::REASON_BUSINESS_MISMATCH => self::UNAVAILABLE,
            StockException::REASON_INVALID_QUANTITY => 'Enter a number with at most 11 whole digits and 3 decimal places.',
            StockException::REASON_ZERO_QUANTITY => 'The quantity must not be zero.',
            StockException::REASON_QUANTITY_OVERFLOW => 'The new balance would be larger than the system supports. Nothing was changed.',
            StockException::REASON_INSUFFICIENT_STOCK => 'There is not enough stock to remove that quantity. Nothing was changed.',
            StockException::REASON_DUPLICATE_OPENING => 'Opening stock was already recorded for this outlet and variant. Use "Adjust stock" to change the balance.',
            StockException::REASON_INVALID_NOTE => 'The note can have at most 255 characters.',
            default => self::FALLBACK,
        };
    }
}
