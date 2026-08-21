<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This completed regional Sheep Pen uses the same FeatureBuilding
        // contract as animal_breeding_*_finished. Flash has no `grown` render
        // state for it, so preserve its contents while returning it to `bare`.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'xuk_sheep_pen_finished')
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
