<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldCurrency extends Model
{
    protected $table = 'world_currencies';

    protected $fillable = [
        'uid', 'currency_unit', 'total', 'earned', 'purchased',
    ];

    protected $casts = [
        'total' => 'integer',
        'earned' => 'integer',
        'purchased' => 'integer',
    ];
}
