<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place where stock levels and stock movements are written.
 *
 * Quantities are decimal strings with at most 3 decimals and are handled with
 * BCMath; they never pass through PHP floats.
 */
class StockService
{
    private const SCALE = 3;

    /** Largest value a DECIMAL(14,3) column can hold. */
    private const MAX_BALANCE = '99999999999.999';

    /** Deadlock retries (only the outermost transaction retries). */
    private const TRANSACTION_ATTEMPTS = 5;

    /**
     * Record the initial stock of a variant in an outlet. Allowed once per pair.
     *
     * @param  string|int|float  $quantity  Positive decimal string (floats are rejected).
     */
    public function recordOpening(
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantity,
        ?string $note = null,
    ): StockMovement {
        return $this->apply(StockMovementType::Opening, $actor, $outlet, $variant, $quantity, $note);
    }

    /**
     * Change the stock by a signed, non-zero delta. The result may not be negative.
     *
     * @param  string|int|float  $quantityDelta  Signed decimal string (floats are rejected).
     */
    public function adjust(
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantityDelta,
        ?string $note = null,
    ): StockMovement {
        return $this->apply(StockMovementType::Adjustment, $actor, $outlet, $variant, $quantityDelta, $note);
    }

    private function apply(
        StockMovementType $type,
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantity,
        ?string $note,
    ): StockMovement {
        $delta = $this->normalizeDelta($type, $quantity);
        $note = $this->normalizeNote($note);

        [$outletId, $variantId] = $this->resolveScope($actor, $outlet, $variant);

        return DB::transaction(function () use ($type, $actor, $outletId, $variantId, $delta, $note): StockMovement {
            $level = $this->lockOrCreateLevel($outletId, $variantId);

            if ($type === StockMovementType::Opening && $this->openingExists($outletId, $variantId)) {
                throw StockException::duplicateOpening();
            }

            $current = bcadd((string) $level->quantity, '0', self::SCALE);
            $after = bcadd($current, $delta, self::SCALE);

            if (bccomp($after, '0', self::SCALE) < 0) {
                throw StockException::insufficientStock($current, $delta);
            }

            if (bccomp($after, self::MAX_BALANCE, self::SCALE) > 0) {
                throw StockException::quantityOverflow();
            }

            $now = now();

            $movement = new StockMovement;
            $movement->forceFill([
                'outlet_id' => $outletId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity_delta' => $delta,
                'quantity_after' => $after,
                'user_id' => $actor->getKey(),
                'note' => $note,
            ])->save();

            StockLevel::query()
                ->whereKey($level->getKey())
                ->update(['quantity' => $after, 'updated_at' => $now]);

            return $movement;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Validate the quantity format and return it as a signed decimal string at scale 3.
     */
    private function normalizeDelta(StockMovementType $type, string|int|float $quantity): string
    {
        if (is_float($quantity)) {
            throw StockException::invalidQuantity('Quantities must be decimal strings, not floats.');
        }

        $quantity = (string) $quantity;

        // Optional minus, up to 11 integer digits (DECIMAL(14,3)), up to 3 decimals.
        // More than 3 decimals is rejected rather than silently rounded.
        if (! preg_match('/^-?\d{1,11}(\.\d{1,3})?$/D', $quantity)) {
            throw StockException::invalidQuantity('The quantity must be a decimal number with at most 3 decimal places.');
        }

        $delta = bcadd($quantity, '0', self::SCALE);
        $sign = bccomp($delta, '0', self::SCALE);

        if ($sign === 0) {
            throw StockException::zeroQuantity();
        }

        if ($type === StockMovementType::Opening && $sign < 0) {
            throw StockException::invalidQuantity('The opening stock quantity must be positive.');
        }

        return $delta;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        if ($note === null || $note === '') {
            return null;
        }

        if (mb_strlen($note) > 255) {
            throw StockException::invalidNote();
        }

        return $note;
    }

    /**
     * Re-read the outlet and variant from the database (never trust the passed
     * models' attributes), then check ownership and that both share one business.
     *
     * @return array{0: int, 1: int} outlet id and variant id
     */
    private function resolveScope(User $actor, Outlet $outlet, ProductVariant $variant): array
    {
        $freshOutlet = Outlet::query()->with('business:id,user_id')->find($outlet->getKey());
        $freshVariant = ProductVariant::query()->with('product:id,business_id')->find($variant->getKey());

        if ($freshOutlet?->business === null || $freshVariant?->product === null) {
            throw StockException::notFound();
        }

        if ((int) $freshOutlet->business->user_id !== (int) $actor->getKey()) {
            throw StockException::unauthorized();
        }

        if ((int) $freshVariant->product->business_id !== (int) $freshOutlet->business_id) {
            throw StockException::businessMismatch();
        }

        return [(int) $freshOutlet->getKey(), (int) $freshVariant->getKey()];
    }

    /**
     * Return the locked stock-level row, creating it first if it does not exist.
     *
     * The lock is attempted first: for an existing row (the common case) this
     * avoids the shared-lock upgrade deadlock that INSERT IGNORE + FOR UPDATE
     * causes between two concurrent writers. Only when the row is missing do we
     * insert it, relying on the unique (outlet_id, product_variant_id) key to
     * make concurrent first writes safe, then lock it again.
     */
    private function lockOrCreateLevel(int $outletId, int $variantId): StockLevel
    {
        $level = $this->lockLevel($outletId, $variantId);

        if ($level !== null) {
            return $level;
        }

        $now = now();

        DB::table('stock_levels')->insertOrIgnore([
            'outlet_id' => $outletId,
            'product_variant_id' => $variantId,
            'quantity' => '0.000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // INSERT IGNORE also swallows foreign-key errors, so a still-missing row
        // means the outlet or variant vanished meanwhile.
        return $this->lockLevel($outletId, $variantId) ?? throw StockException::notFound();
    }

    private function lockLevel(int $outletId, int $variantId): ?StockLevel
    {
        return StockLevel::query()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Called while holding the stock-level row lock, so writers of the same pair
     * are serialized. A locking read always sees the latest committed rows, even
     * if the surrounding transaction already holds an older snapshot.
     */
    private function openingExists(int $outletId, int $variantId): bool
    {
        return StockMovement::query()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->where('type', StockMovementType::Opening->value)
            ->lockForUpdate()
            ->value('id') !== null;
    }
}
