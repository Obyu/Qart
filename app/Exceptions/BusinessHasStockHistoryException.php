<?php

namespace App\Exceptions;

/**
 * Thrown when a business cannot be deleted because its outlets have stock history.
 *
 * Extends StockException, so callers may catch either class and read $reason.
 */
class BusinessHasStockHistoryException extends StockException
{
    public const REASON_BUSINESS_HAS_STOCK_HISTORY = 'business_has_stock_history';

    public function __construct()
    {
        parent::__construct(
            self::REASON_BUSINESS_HAS_STOCK_HISTORY,
            'This business has stock history and cannot be deleted.'
        );
    }
}
