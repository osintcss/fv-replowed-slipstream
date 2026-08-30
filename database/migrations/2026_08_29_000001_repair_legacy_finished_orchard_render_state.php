<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repair ordinary finished orchards that were persisted with the
     * crop-style `grown` state. Their contents and placement remain intact;
     * only the invalid render state changes to the normal `bare` state.
     */
    public function up(): void
    {
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'orchard_featurebuilding_finished')
            ->where('class_name', 'OrchardFeatureBuilding')
            ->where('state', 'grown')
            ->update([
                'state' => 'bare',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore `grown`: it is not an OrchardFeatureBuilding state.
    }
};
