<?php

namespace Tests\Unit;

use App\Exceptions\StockException;
use App\Filament\Support\StockFailureMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockFailureMessageTest extends TestCase
{
    /**
     * @return array<string, array{0: StockException}>
     */
    public static function ownershipFailures(): array
    {
        return [
            'unauthorized' => [StockException::unauthorized()],
            'not found' => [StockException::notFound()],
            'business mismatch' => [StockException::businessMismatch()],
        ];
    }

    #[DataProvider('ownershipFailures')]
    public function test_ownership_failures_share_one_message_that_confirms_nothing(StockException $exception): void
    {
        $this->assertSame(StockFailureMessage::UNAVAILABLE, StockFailureMessage::describe($exception));
    }

    /**
     * @return array<string, array{0: StockException}>
     */
    public static function actionableFailures(): array
    {
        return [
            'invalid quantity' => [StockException::invalidQuantity('raw detail')],
            'zero quantity' => [StockException::zeroQuantity()],
            'overflow' => [StockException::quantityOverflow()],
            'insufficient stock' => [StockException::insufficientStock('2.000', '-5.000', 12)],
            'duplicate opening' => [StockException::duplicateOpening()],
            'invalid note' => [StockException::invalidNote()],
        ];
    }

    #[DataProvider('actionableFailures')]
    public function test_a_known_failure_gets_its_own_message(StockException $exception): void
    {
        $message = StockFailureMessage::describe($exception);

        $this->assertNotSame('', $message);
        $this->assertNotSame(StockFailureMessage::FALLBACK, $message);
        $this->assertNotSame(StockFailureMessage::UNAVAILABLE, $message);
        $this->assertStringNotContainsString('raw detail', $message);
    }

    public function test_the_message_never_leaks_balances_or_variant_ids(): void
    {
        $message = StockFailureMessage::describe(StockException::insufficientStock('2.000', '-5.000', 12));

        $this->assertStringNotContainsString('2.000', $message);
        $this->assertStringNotContainsString('-5.000', $message);
        $this->assertStringNotContainsString('#12', $message);
    }

    public function test_failures_that_do_not_apply_to_manual_changes_use_the_fallback(): void
    {
        $this->assertSame(StockFailureMessage::FALLBACK, StockFailureMessage::describe(StockException::emptySale()));
        $this->assertSame(StockFailureMessage::FALLBACK, StockFailureMessage::describe(StockException::saleAlreadyRecorded()));
    }

    public function test_an_unknown_reason_uses_the_fallback_and_hides_its_message(): void
    {
        $message = StockFailureMessage::describe(new StockException('brand_new_reason', 'raw detail'));

        $this->assertSame(StockFailureMessage::FALLBACK, $message);
        $this->assertStringNotContainsString('raw detail', $message);
    }
}
