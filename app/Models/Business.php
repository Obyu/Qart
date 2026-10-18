<?php

namespace App\Models;

use App\Exceptions\BusinessHasStockHistoryException;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Fillable(['name'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Delete the business atomically.
     *
     * A business with stock movement history is never deleted: the ledger is
     * preserved and BusinessHasStockHistoryException is thrown before anything
     * is removed.
     *
     * products.category_id is RESTRICT, so products must be removed before the
     * database cascades this business's categories. If anything fails, or the
     * delete is cancelled, the product deletions are rolled back.
     *
     * @throws BusinessHasStockHistoryException
     */
    public function delete(): ?bool
    {
        DB::beginTransaction();

        try {
            $this->guardAgainstStockHistory();

            $this->products()->chunkById(200, fn ($products) => $products->each->delete());

            $deleted = parent::delete();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($deleted === false) {
            DB::rollBack();

            return false;
        }

        DB::commit();

        return $deleted;
    }

    /**
     * Must run inside the delete transaction, before any row is removed.
     *
     * The outlet rows are locked first: a concurrent stock write needs a shared
     * lock on its outlet row, so it waits for this transaction instead of adding a
     * movement after the check. The existence read after the lock therefore sees
     * all committed history.
     */
    private function guardAgainstStockHistory(): void
    {
        $outletIds = $this->outlets()->lockForUpdate()->pluck('id');

        if ($outletIds->isNotEmpty()
            && StockMovement::query()->whereIn('outlet_id', $outletIds)->exists()) {
            throw new BusinessHasStockHistoryException;
        }
    }
}
