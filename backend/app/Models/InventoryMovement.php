<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected $fillable = [
        'item_id',
        'movement_date',
        'movement_type',
        'qty_in',
        'qty_out',
        'unit_cost',
        'reference_id',
        'reference_type',
        'notes',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'qty_in' => 'decimal:2',
        'qty_out' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
