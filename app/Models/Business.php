<?php

namespace App\Models;

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
    /** @use HasFactory<\Database\Factories\BusinessFactory> */
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

    /**
     * Delete the business atomically.
     *
     * products.category_id is RESTRICT, so products must be removed before the
     * database cascades this business's categories. If anything fails, or the
     * delete is cancelled, the product deletions are rolled back.
     */
    public function delete(): ?bool
    {
        DB::beginTransaction();

        try {
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
}
