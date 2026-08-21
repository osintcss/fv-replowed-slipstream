<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The completed Baby Bunny Hutch is an animal-storage FeatureBuilding.
        // `grown` has no Flash render state for it; changing only the state to
        // `bare` preserves its contents (the stored bunnies) and location.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'babybunnyhutch_finished')
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
