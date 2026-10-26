<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderParticipantItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(OrderParticipant::class, 'order_participant_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
