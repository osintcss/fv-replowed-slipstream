<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert legacy completed orchards from their construction contract to
     * the functional OrchardFeatureBuilding contract expected by Flash.
     */
    public function up(): void
    {
        DB::table('world_objects')
            ->where('item_name', 'orchard_featurebuilding')
            ->where('class_name', 'OrchardConstructionBuilding')
            ->where('state', 'built')
            ->update([
                'item_name' => 'orchard_featurebuilding_finished',
                'class_name' => 'OrchardFeatureBuilding',
                'state' => 'bare',
                'updated_at' => now(),
            ]);
    }

    /**
     * The original construction records cannot be distinguished from newly
     * created finished orchards after the repair, so this is intentionally
     * non-reversible.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
