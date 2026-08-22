<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ChickenCoopBuilding inherits Flash's harvestable-resource renderer,
        // whose ready state is `ripe`. The prior compatibility migration used
        // the crop-only value `grown`, which leaves a fully populated Coop as
        // a ground shadow. Preserve every other field, especially contents.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('class_name', 'ChickenCoopBuilding')
            ->where('state', 'grown')
            ->update([
                'state' => 'ripe',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // `grown` is not a valid ChickenCoopBuilding render state, so do not
        // restore it on rollback.
    }
};
