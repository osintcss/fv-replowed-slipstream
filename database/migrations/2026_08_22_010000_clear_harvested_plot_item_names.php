<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Earlier harvest handlers changed a normal Plot to fallow but left its
     * crop item name intact. Flash then rendered the old crop over a plot it
     * otherwise treated as fallow. A fallow plot cannot legitimately retain a
     * planted item, so this repair is lossless.
     */
    public function up(): void
    {
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('class_name', 'like', '%Plot%')
            ->where('state', 'fallow')
            ->whereNotNull('item_name')
            ->update([
                'item_name' => null,
                'plant_time' => 0,
            ]);
    }

    public function down(): void
    {
        // The prior item cannot be reconstructed safely; no rollback action.
    }
};
