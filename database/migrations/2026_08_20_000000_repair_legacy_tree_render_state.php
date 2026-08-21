<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FFDec: Classes.Tree supports `bare` and `ripe`; the former generic
        // world serializer saved mature trees as `grown`, which renders only
        // their ground shadow after a reload.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('class_name', 'Tree')
            ->where('state', 'grown')
            ->update([
                'state' => 'ripe',
                'updated_at' => now(),
            ]);

        // Verified duplicate on Goober's farm: retain object 426 and
        // soft-delete only the duplicate temporary-ID record at the same
        // Horseshoe Tree position. This is reversible database state.
        DB::table('world_objects')
            ->where('world_id', 3)
            ->where('object_id', 63000)
            ->where('item_name', 'horseshoestree')
            ->where('position_x', 67)
            ->where('position_y', 53)
            ->where('deleted', false)
            ->update([
                'deleted' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore `grown`: it is an invalid Flash Tree render state.
        // The duplicate remains soft-deleted rather than being resurrected.
    }
};
