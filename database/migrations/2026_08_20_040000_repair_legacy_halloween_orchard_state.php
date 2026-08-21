<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FFDec: OrchardFeatureBuilding recognizes `ripe` and otherwise
        // restores its normal `bare` presentation. `grown` has no renderer;
        // update only that state so orchard contents and placement are kept.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'xhworchard_featurebuilding_finished')
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
