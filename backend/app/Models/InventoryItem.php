<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $table = 'inventory_items';

    protected $fillable = [
        'sku',
        'item_name',
        'category',
        'unit',
        'standard_cost',
        'min_stock',
        'inventory_coa_code',
        'expense_coa_code',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'standard_cost' => 'decimal:2',
        'min_stock' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function roomTypeAmenities()
    {
        return $this->hasMany(RoomTypeAmenity::class, 'inventory_item_id');
    }
}
