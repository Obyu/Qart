<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderParticipant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'total_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function itemAllocations(): HasMany
    {
        return $this->hasMany(OrderParticipantItem::class);
    }
}
