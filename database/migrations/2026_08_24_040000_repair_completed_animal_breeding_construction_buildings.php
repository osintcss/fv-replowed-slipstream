<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Construction-completion requests for animal breeding habitats can
     * arrive as a terminal `built` construction object. The Flash client
     * then reloads it as an unfinished building, even though its contents
     * (for example, pets placed in a Pet Run) were saved correctly.
     *
     * Preserve the stored contents and component data while converting those
     * terminal construction records to the finished FeatureBuilding contract.
     */
    public function up(): void
    {
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('state', 'built')
            ->where('item_name', 'like', 'animal_breeding_%')
            ->where('item_name', 'not like', '%_finished')
            ->where('class_name', 'like', 'Animal%ConstructionBuilding')
            ->orderBy('id')
            ->each(function (object $building): void {
                DB::table('world_objects')
                    ->where('id', $building->id)
                    ->update([
                        'item_name' => $building->item_name . '_finished',
                        'class_name' => 'FeatureBuilding',
                        'state' => 'bare',
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // The completed resource is indistinguishable from a normal finished
        // placement after conversion, so this repair is intentionally final.
    }
};
