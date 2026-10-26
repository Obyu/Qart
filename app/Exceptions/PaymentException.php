<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Expected, recoverable rejection of a payment operation.
 *
 * Database errors, integrity violations, deadlocks and lock timeouts are not
 * wrapped: they propagate unchanged.
 */
class PaymentException extends RuntimeException
{
    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_ORDER_NOT_OPEN = 'order_not_open';

    public const REASON_ORDER_ALREADY_BILLED = 'order_already_billed';

    public const REASON_EMPTY_ORDER = 'empty_order';

    public const REASON_INVALID_MODE = 'invalid_mode';

    public const REASON_INVALID_METHOD = 'invalid_method';

    public const REASON_INVALID_AMOUNT = 'invalid_amount';

    public const REASON_INVALID_PARTICIPANT = 'invalid_participant';

    public const REASON_INVALID_ALLOCATION = 'invalid_allocation';

    public const REASON_ALLOCATION_MISMATCH = 'allocation_mismatch';

    public const REASON_PAYMENT_ALREADY_EXISTS = 'payment_already_exists';

    public const REASON_PAYMENT_NOT_PENDING = 'payment_not_pending';

    public const REASON_INSUFFICIENT_CASH = 'insufficient_cash';

    public const REASON_QRIS_CONFIRMATION_REQUIRED = 'qris_confirmation_required';

    public const REASON_OBLIGATION_NOT_SETTLED = 'obligation_not_settled';

    public const REASON_ORDER_NOT_SETTLED = 'order_not_settled';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(
            self::REASON_UNAUTHORIZED,
            'You do not own the business this payment operation refers to.',
        );
    }

    public static function notFound(): self
    {
        return new self(
            self::REASON_NOT_FOUND,
            'The order, participant or payment no longer exists.',
        );
    }

    public static function orderNotOpen(): self
    {
        return new self(
            self::REASON_ORDER_NOT_OPEN,
            'The order is not open and cannot accept payment changes.',
        );
    }

    public static function orderAlreadyBilled(): self
    {
        return new self(
            self::REASON_ORDER_ALREADY_BILLED,
            'The order already has a bill and its payment configuration is frozen.',
        );
    }

    public static function emptyOrder(): self
    {
        return new self(
            self::REASON_EMPTY_ORDER,
            'An order without items cannot be billed.',
        );
    }

    public static function invalidMode(): self
    {
        return new self(
            self::REASON_INVALID_MODE,
            'The payment mode must be direct, split_items or split_equal.',
        );
    }

    public static function invalidMethod(): self
    {
        return new self(
            self::REASON_INVALID_METHOD,
            'The payment method must be cash or qris.',
        );
    }

    public static function invalidAmount(string $message): self
    {
        return new self(self::REASON_INVALID_AMOUNT, $message);
    }

    public static function invalidParticipant(): self
    {
        return new self(
            self::REASON_INVALID_PARTICIPANT,
            'The participant does not belong to this order or is invalid.',
        );
    }

    public static function invalidAllocation(string $message): self
    {
        return new self(self::REASON_INVALID_ALLOCATION, $message);
    }

    public static function allocationMismatch(): self
    {
        return new self(
            self::REASON_ALLOCATION_MISMATCH,
            'Allocated item quantities must match every order item quantity exactly.',
        );
    }

    public static function paymentAlreadyExists(): self
    {
        return new self(
            self::REASON_PAYMENT_ALREADY_EXISTS,
            'An active payment already exists for this obligation.',
        );
    }

    public static function paymentNotPending(): self
    {
        return new self(
            self::REASON_PAYMENT_NOT_PENDING,
            'Only a pending payment can be confirmed or rejected.',
        );
    }

    public static function insufficientCash(): self
    {
        return new self(
            self::REASON_INSUFFICIENT_CASH,
            'The amount received must be at least the amount due.',
        );
    }

    public static function qrisConfirmationRequired(): self
    {
        return new self(
            self::REASON_QRIS_CONFIRMATION_REQUIRED,
            'QRIS payments require explicit confirmation after payment verification.',
        );
    }

    public static function obligationNotSettled(): self
    {
        return new self(
            self::REASON_OBLIGATION_NOT_SETTLED,
            'This payment obligation has not been settled.',
        );
    }

    public static function orderNotSettled(): self
    {
        return new self(
            self::REASON_ORDER_NOT_SETTLED,
            'The order cannot be completed until every payment obligation is settled.',
        );
    }
}
