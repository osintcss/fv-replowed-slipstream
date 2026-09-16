<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency receipts for client actions that have no world-object source.
 *
 * Gift-backed storage is the important current caller: Flash removes the
 * Giftbox item locally before sending TStoreItem, so a retried request cannot
 * be deduplicated by locking a standalone resource row.
 */
class WorldActionReceipt extends Model
{
    protected $table = 'world_action_receipts';

    protected $fillable = [
        'uid',
        'action',
        'request_key',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}
