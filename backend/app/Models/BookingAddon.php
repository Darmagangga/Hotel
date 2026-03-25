<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingAddon extends Model
{
    protected $fillable = [
        'booking_id',
        'addon_type',
        'reference_id',
        'service_date',
        'start_date',
        'end_date',
        'qty',
        'unit_price',
        'total_price',
        'status',
        'notes',
    ];

    protected $casts = [
        'service_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
