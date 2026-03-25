<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoaAccount extends Model
{
    protected $table = 'coa_accounts';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'account_name',
        'category',
        'normal_balance',
        'is_active',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
