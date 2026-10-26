<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales order of one outlet.
 *
 * Intentionally has no #[Fillable]: business, outlet and status are never taken
 * from request data. They are written by OrderService, which validates ownership.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'bill_total' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(OrderParticipant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
