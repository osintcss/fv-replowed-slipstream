<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceAudit extends Model
{
    protected $table = 'resource_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
