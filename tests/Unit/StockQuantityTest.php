<?php

namespace Tests\Unit;

use App\Filament\Support\StockQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockQuantityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedInputs(): array
    {
        return [
            'whole number' => ['10', '10'],
            'one decimal' => ['2.5', '2.5'],
            'smallest unit' => ['0.001', '0.001'],
            'trailing zeros are kept as typed' => ['12.500', '12.500'],
            'largest supported value' => ['99999999999.999', '99999999999.999'],
            'surrounding spaces are trimmed' => ['  5  ', '5'],
            'surrounding tab and newline are trimmed' => ["\t7.5\n", '7.5'],
        ];
    }

    #[DataProvider('acceptedInputs')]
    public function test_a_plain_positive_decimal_is_accepted_exactly_as_typed(string $input, string $expected): void
    {
        $this->assertSame($expected, StockQuantity::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'empty' => [''],
            'only spaces' => ['   '],
            'zero' => ['0'],
            'zero with one decimal' => ['0.0'],
            'zero with decimals' => ['0.000'],
            'several zeros' => ['000'],
            'negative' => ['-1'],
            'explicit plus' => ['+1'],
            'trailing dot' => ['1.'],
            'leading dot' => ['.5'],
            'two dots' => ['1.2.3'],
            'comma decimal' => ['1,5'],
            'thousands separator' => ['1,000'],
            'inner space' => ['1 000'],
            'too many decimals' => ['1.2345'],
            'too many integer digits' => ['123456789012'],
            'scientific notation' => ['1e3'],
            'hexadecimal' => ['0x10'],
            'letters' => ['abc'],
            'inner newline' => ["1\n2"],
            'fullwidth digit' => ["\u{FF11}"],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function test_anything_that_is_not_a_positive_decimal_is_rejected(string $input): void
    {
        $this->assertNull(StockQuantity::normalize($input));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonStringInputs(): array
    {
        return [
            'null' => [null],
            'integer' => [5],
            'float' => [5.5],
            'boolean' => [true],
            'array' => [['5']],
        ];
    }

    #[DataProvider('nonStringInputs')]
    public function test_a_value_that_is_not_a_string_is_rejected(mixed $input): void
    {
        $this->assertNull(StockQuantity::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function signedDeltas(): array
    {
        return [
            'add whole' => [StockQuantity::DIRECTION_ADD, '10', '10'],
            'add keeps decimals as typed' => [StockQuantity::DIRECTION_ADD, '2.500', '2.500'],
            'remove whole' => [StockQuantity::DIRECTION_REMOVE, '10', '-10'],
            'remove keeps decimals as typed' => [StockQuantity::DIRECTION_REMOVE, '2.500', '-2.500'],
            'remove smallest unit' => [StockQuantity::DIRECTION_REMOVE, '0.001', '-0.001'],
            'remove trims the amount' => [StockQuantity::DIRECTION_REMOVE, ' 3 ', '-3'],
        ];
    }

    #[DataProvider('signedDeltas')]
    public function test_the_direction_and_amount_become_the_signed_delta(string $direction, string $amount, string $expected): void
    {
        $this->assertSame($expected, StockQuantity::signed($direction, $amount));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidAmounts(): array
    {
        $amounts = [
            'zero' => '0',
            'zero with decimals' => '0.000',
            'negative' => '-5',
            'letters' => 'abc',
            'empty' => '',
            'too many decimals' => '1.2345',
        ];

        $cases = [];

        foreach ([StockQuantity::DIRECTION_ADD, StockQuantity::DIRECTION_REMOVE] as $direction) {
            foreach ($amounts as $label => $amount) {
                $cases[$direction.': '.$label] = [$direction, $amount];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidAmounts')]
    public function test_signed_refuses_an_amount_that_is_not_positive(string $direction, string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockQuantity::signed($direction, $amount);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownDirections(): array
    {
        return [
            'another word' => ['subtract'],
            'different case' => ['ADD'],
            'leading space' => [' add'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unknownDirections')]
    public function test_signed_refuses_an_unknown_direction(string $direction): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockQuantity::signed($direction, '1');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function displayedQuantities(): array
    {
        return [
            'trailing zeros removed' => ['10.000', '10'],
            'one decimal kept' => ['2.500', '2.5'],
            'tenths' => ['0.100', '0.1'],
            'integer zeros are never touched' => ['100.000', '100'],
            'whole number without decimals' => ['100', '100'],
            'zero' => ['0.000', '0'],
            'smallest unit' => ['0.001', '0.001'],
            'inner zero is kept' => ['1000.050', '1000.05'],
            'negative' => ['-2.500', '-2.5'],
            'negative zero' => ['-0.000', '0'],
            'largest value keeps every digit' => ['99999999999.999', '99999999999.999'],
            'empty string' => ['', '0'],
        ];
    }

    #[DataProvider('displayedQuantities')]
    public function test_format_drops_only_the_useless_decimal_zeros(string $quantity, string $expected): void
    {
        $this->assertSame($expected, StockQuantity::format($quantity));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function displayedDeltas(): array
    {
        return [
            'positive gets a plus' => ['10.000', '+10'],
            'fraction gets a plus' => ['0.001', '+0.001'],
            'integer zeros are never touched' => ['100.000', '+100'],
            'negative keeps its minus' => ['-2.500', '-2.5'],
            'negative integer zeros are never touched' => ['-100.000', '-100'],
            'zero has no sign' => ['0.000', '0'],
            'negative zero has no sign' => ['-0.000', '0'],
        ];
    }

    #[DataProvider('displayedDeltas')]
    public function test_format_signed_adds_a_plus_only_to_positive_values(string $delta, string $expected): void
    {
        $this->assertSame($expected, StockQuantity::formatSigned($delta));
    }

    public function test_the_signed_delta_formats_back_to_what_the_person_typed(): void
    {
        $this->assertSame('+2.5', StockQuantity::formatSigned(StockQuantity::signed(StockQuantity::DIRECTION_ADD, '2.500')));
        $this->assertSame('-2.5', StockQuantity::formatSigned(StockQuantity::signed(StockQuantity::DIRECTION_REMOVE, '2.500')));
    }
}
