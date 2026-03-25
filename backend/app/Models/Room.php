<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    protected $fillable = [
        'room_type_id',
        'room_code',
        'room_name',
        'floor',
        'status',
        'coa_receivable_code',
        'coa_revenue_code',
        'notes',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function getRouteKeyName(): string
    {
        return 'room_code';
    }

    public function coaReceivable(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class, 'coa_receivable_code', 'code');
    }

    public function coaRevenue(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class, 'coa_revenue_code', 'code');
    }
}
