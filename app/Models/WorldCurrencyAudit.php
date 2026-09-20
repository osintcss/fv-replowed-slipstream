<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldCurrencyAudit extends Model
{
    protected $table = 'world_currency_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
