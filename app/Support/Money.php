<?php

namespace App\Support;

use InvalidArgumentException;
use LogicException;

/**
 * Fixed-point money arithmetic on decimal strings with exactly 2 decimals.
 *
 * Money never passes through PHP floats: amounts are strings (or ints) and all
 * arithmetic uses BCMath. The range matches DECIMAL(14,2): non-negative, at most
 * 12 integer digits. Invalid input is rejected, never rounded.
 */
final class Money
{
    public const SCALE = 2;

    /** Largest value a DECIMAL(14,2) column can hold. */
    public const MAX = '999999999999.99';

    /** Upper bound for allocateEqually(), so a typo cannot create thousands of shares. */
    public const MAX_PARTS = 100;

    /**
     * Validate an amount and return it as a decimal string with exactly 2 decimals.
     *
     * @param  string|int|float  $amount  Non-negative, at most 2 decimals (floats are rejected).
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string|int|float $amount): string
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException('Money amounts must be decimal strings, not floats.');
        }

        $text = (string) $amount;

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/D', $text) !== 1) {
            throw new InvalidArgumentException('A money amount must be a non-negative decimal with at most 2 decimals and 12 integer digits.');
        }

        return bcadd($text, '0', self::SCALE);
    }

    /**
     * @throws InvalidArgumentException on invalid input or when the result exceeds MAX
     */
    public static function add(string|int|float $a, string|int|float $b): string
    {
        return self::fit(bcadd(self::normalize($a), self::normalize($b), self::SCALE));
    }

    /**
     * @throws InvalidArgumentException on invalid input or when the result would be negative
     */
    public static function sub(string|int|float $a, string|int|float $b): string
    {
        $result = bcsub(self::normalize($a), self::normalize($b), self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('The result of a money subtraction cannot be negative.');
        }

        return $result;
    }

    /**
     * Unit price times a whole quantity of at least 1. Exact: a 2-decimal price
     * times an integer never needs rounding.
     *
     * @param  string|int|float  $quantity  Whole number from 1 to 9,999,999,999 (floats are rejected).
     *
     * @throws InvalidArgumentException
     */
    public static function mulQuantity(string|int|float $unitPrice, string|int|float $quantity): string
    {
        if (is_float($quantity)) {
            throw new InvalidArgumentException('Quantities must be whole numbers, not floats.');
        }

        $quantity = (string) $quantity;

        if (preg_match('/^[1-9]\d{0,9}$/D', $quantity) !== 1) {
            throw new InvalidArgumentException('The quantity must be a whole number of at least 1.');
        }

        return self::fit(bcmul(self::normalize($unitPrice), $quantity, self::SCALE));
    }

    /**
     * @param  iterable<string|int|float>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /**
     * Numeric comparison: -1, 0 or 1. Never compares the strings as text.
     */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /**
     * Split a total into equal shares without losing or creating a cent.
     *
     * The total is handled in whole cents. Every share gets the rounded-down
     * amount and the leftover cents go one each to the earliest shares, so the
     * result is deterministic, never increases along the list and always adds up
     * to the total exactly. The list index is the participant position.
     *
     * @return list<string> $parts amounts with 2 decimals
     *
     * @throws InvalidArgumentException
     */
    public static function allocateEqually(string|int|float $total, int $parts): array
    {
        if ($parts < 1 || $parts > self::MAX_PARTS) {
            throw new InvalidArgumentException('The number of shares must be between 1 and '.self::MAX_PARTS.'.');
        }

        $total = self::normalize($total);
        $cents = bcmul($total, '100', 0);
        $base = bcdiv($cents, (string) $parts, 0);
        $remainder = (int) bcmod($cents, (string) $parts, 0);

        $shares = [];

        for ($index = 0; $index < $parts; $index++) {
            $shareCents = bcadd($base, $index < $remainder ? '1' : '0', 0);
            $shares[] = bcdiv($shareCents, '100', self::SCALE);
        }

        if (bccomp(self::sum($shares), $total, self::SCALE) !== 0) {
            throw new LogicException('The shares do not add up to the total.');
        }

        return $shares;
    }

    private static function fit(string $amount): string
    {
        if (bccomp($amount, self::MAX, self::SCALE) > 0) {
            throw new InvalidArgumentException('The amount exceeds the DECIMAL(14,2) range.');
        }

        return $amount;
    }
}
