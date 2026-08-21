<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FFDec: FeatureBuilding recognizes `bare` and `ripe`, not `grown`.
        // This applies even where featured items are present; retain them and
        // all contents while correcting only the invalid renderer state.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('class_name', 'FeatureBuilding')
            ->where('state', 'grown')
            ->where('item_name', 'like', 'animal_breeding_%_finished')
            ->update([
                'state' => 'bare',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not restore `grown`: it is not a FeatureBuilding render state.
    }
};
