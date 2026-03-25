<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomTypeAmenity extends Model
{
    protected $table = 'room_type_amenities';

    protected $fillable = [
        'room_type_code',
        'inventory_item_id',
        'quantity',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_code', 'code');
    }
}
