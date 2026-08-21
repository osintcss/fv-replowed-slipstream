<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The completed Bloom Garden is a storage FeatureBuilding. Its Flash
        // renderer has no `grown` visual, so change only the render state and
        // retain its stored contents and all placement data.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'flower_garden_finished')
            ->where('class_name', 'FeatureBuilding')
            ->where('state', 'grown')
            ->update([
                'state' => 'bare',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore `grown`: it is an invalid Flash FeatureBuilding state.
    }
};
