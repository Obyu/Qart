<?php

namespace App\Filament\Support;

use InvalidArgumentException;

/**
 * Input and display rules for stock quantities in the Filament UI.
 *
 * Quantities are decimal strings (DECIMAL(14,3) in the database). Nothing here
 * converts to a float; the only "arithmetic" is prefixing a minus sign. The
 * input rule mirrors StockService (up to 11 integer digits, up to 3 decimals)
 * but StockService stays the authority and validates again.
 */
final class StockQuantity
{
    public const DIRECTION_ADD = 'add';

    public const DIRECTION_REMOVE = 'remove';

    /** Unsigned: the direction is chosen separately, so a typed sign is always an error. */
    private const PATTERN = '/^\d{1,11}(\.\d{1,3})?$/D';

    /**
     * Validate a typed positive quantity. Surrounding whitespace is trimmed;
     * anything else that is not a plain decimal, or that is zero, is rejected.
     *
     * @return string|null the trimmed quantity exactly as typed, or null when invalid
     */
    public static function normalize(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $input = trim($input);

        if (preg_match(self::PATTERN, $input) !== 1) {
            return null;
        }

        return bccomp($input, '0', 3) === 0 ? null : $input;
    }

    /**
     * Combine a direction and a positive amount into the signed delta that
     * StockService::adjust() expects. Removing prefixes a minus sign.
     *
     * @throws InvalidArgumentException
     */
    public static function signed(string $direction, string $amount): string
    {
        $amount = self::normalize($amount)
            ?? throw new InvalidArgumentException('The amount must be a positive decimal string.');

        return match ($direction) {
            self::DIRECTION_ADD => $amount,
            self::DIRECTION_REMOVE => '-'.$amount,
            default => throw new InvalidArgumentException('Unknown stock direction.'),
        };
    }

    /**
     * "10.000" becomes "10" and "2.500" becomes "2.5". Zeros of the integer
     * part are never touched: "100.000" becomes "100".
     */
    public static function format(string $quantity): string
    {
        if (str_contains($quantity, '.')) {
            $quantity = rtrim(rtrim($quantity, '0'), '.');
        }

        return in_array($quantity, ['', '-0'], true) ? '0' : $quantity;
    }

    /**
     * Like format(), with an explicit "+" on positive values: "+10", "-2.5".
     */
    public static function formatSigned(string $delta): string
    {
        $formatted = self::format($delta);

        if ($formatted === '0' || str_starts_with($formatted, '-')) {
            return $formatted;
        }

        return '+'.$formatted;
    }
}
