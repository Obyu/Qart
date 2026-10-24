<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: string|int, 1: string}>
     */
    public static function validAmounts(): array
    {
        return [
            'whole string' => ['5', '5.00'],
            'one decimal' => ['5.5', '5.50'],
            'zero' => ['0', '0.00'],
            'leading zeros' => ['007.5', '7.50'],
            'int' => [12, '12.00'],
            'maximum' => ['999999999999.99', '999999999999.99'],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_normalize_returns_two_decimals(string|int $amount, string $expected): void
    {
        $this->assertSame($expected, Money::normalize($amount));
    }

    /**
     * @return array<string, array{0: string|int|float}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-1.00'],
            'three decimals' => ['1.005'],
            'exponent' => ['1e3'],
            'comma' => ['1,50'],
            'leading space' => [' 1.00'],
            'trailing newline' => ["1.00\n"],
            'no integer part' => ['.50'],
            'no fraction digits' => ['1.'],
            'text' => ['abc'],
            'too many integer digits' => ['1000000000000.00'],
            'negative int' => [-5],
            'fractional float' => [1.5],
            'whole float' => [2.0],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_normalize_rejects_invalid_amounts(string|int|float $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::normalize($amount);
    }

    public function test_add_is_exact_where_floats_are_not(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('3.50', Money::add(1, '2.5'));
        $this->assertSame('0.00', Money::add('0', '0'));
    }

    public function test_add_rejects_a_result_above_the_column_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::add('999999999999.99', '0.01');
    }

    public function test_sub_returns_the_difference_and_zero(): void
    {
        $this->assertSame('3.75', Money::sub('5.00', '1.25'));
        $this->assertSame('0.00', Money::sub('5', '5.00'));
    }

    public function test_sub_rejects_a_negative_result(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::sub('1', '2');
    }

    /**
     * @return array<string, array{0: string, 1: string|int, 2: string}>
     */
    public static function quantityCases(): array
    {
        return [
            'three of a price' => ['19999.99', 3, '59999.97'],
            'one cent a million times' => ['0.01', 1000000, '10000.00'],
            'free item' => ['0.00', 5, '0.00'],
            'quantity as string' => ['12.50', '4', '50.00'],
        ];
    }

    #[DataProvider('quantityCases')]
    public function test_mul_quantity_is_exact(string $price, string|int $quantity, string $expected): void
    {
        $this->assertSame($expected, Money::mulQuantity($price, $quantity));
    }

    /**
     * @return array<string, array{0: string|int|float}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'zero int' => [0],
            'zero string' => ['0'],
            'negative' => [-1],
            'fraction' => ['1.5'],
            'float' => [1.5],
            'whole float' => [2.0],
            'empty' => [''],
            'leading zero' => ['01'],
            'eleven digits' => ['12345678901'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_mul_quantity_rejects_invalid_quantities(string|int|float $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::mulQuantity('1.00', $quantity);
    }

    public function test_mul_quantity_rejects_a_result_above_the_column_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::mulQuantity('999999999999.99', 2);
    }

    public function test_sum_adds_exactly(): void
    {
        $this->assertSame('0.00', Money::sum([]));
        $this->assertSame('0.60', Money::sum(['0.10', '0.20', '0.30']));
    }

    public function test_compare_is_numeric_not_textual(): void
    {
        $this->assertSame(0, Money::compare('1.0', '1.00'));
        $this->assertSame(-1, Money::compare('2', '10'));
        $this->assertSame(1, Money::compare('10.01', '10.00'));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: list<string>}>
     */
    public static function allocationCases(): array
    {
        return [
            'one share' => ['100.00', 1, ['100.00']],
            'hundred in three' => ['100.00', 3, ['33.34', '33.33', '33.33']],
            'even split' => ['10.00', 4, ['2.50', '2.50', '2.50', '2.50']],
            'zero total' => ['0.00', 3, ['0.00', '0.00', '0.00']],
            'one cent in three' => ['0.01', 3, ['0.01', '0.00', '0.00']],
            'two cents in three' => ['0.02', 3, ['0.01', '0.01', '0.00']],
            'seven shares' => ['99.99', 7, ['14.29', '14.29', '14.29', '14.28', '14.28', '14.28', '14.28']],
            'large total' => ['1234567.89', 5, ['246913.58', '246913.58', '246913.58', '246913.58', '246913.57']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('allocationCases')]
    public function test_allocate_equally_is_deterministic_and_exact(string $total, int $parts, array $expected): void
    {
        $this->assertSame($expected, Money::allocateEqually($total, $parts));
        $this->assertSame(0, Money::compare(Money::sum($expected), $total));
    }

    public function test_allocation_never_loses_or_creates_money(): void
    {
        for ($cents = 0; $cents <= 500; $cents++) {
            $total = bcdiv((string) $cents, '100', 2);

            for ($parts = 1; $parts <= 13; $parts++) {
                $shares = Money::allocateEqually($total, $parts);
                $context = "total {$total} in {$parts}";

                $this->assertCount($parts, $shares, $context);
                $this->assertSame(0, Money::compare(Money::sum($shares), $total), $context);
                $this->assertLessThanOrEqual(0, Money::compare(Money::sub($shares[0], $shares[$parts - 1]), '0.01'), $context);

                for ($index = 0; $index < $parts - 1; $index++) {
                    $this->assertGreaterThanOrEqual(0, Money::compare($shares[$index], $shares[$index + 1]), $context);
                }
            }
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidShareCounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the limit' => [Money::MAX_PARTS + 1],
        ];
    }

    #[DataProvider('invalidShareCounts')]
    public function test_allocate_equally_rejects_invalid_share_counts(int $parts): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::allocateEqually('10.00', $parts);
    }

    public function test_allocate_equally_rejects_an_invalid_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::allocateEqually('1.005', 3);
    }
}
