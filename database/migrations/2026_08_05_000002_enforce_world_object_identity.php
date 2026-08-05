<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imports and older versions could have multiple rows for one Flash
        // object identity. Retain the active, most recently updated row before
        // adding the constraint that prevents the race from recurring.
        $duplicates = DB::table('world_objects')
            ->select('world_id', 'object_id')
            ->groupBy('world_id', 'object_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('world_objects')
                ->where('world_id', $duplicate->world_id)
                ->where('object_id', $duplicate->object_id)
                ->orderBy('deleted')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id']);

            $keepId = $rows->shift()->id;
            DB::table('world_objects')
                ->where('world_id', $duplicate->world_id)
                ->where('object_id', $duplicate->object_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        // The older service could also create a fresh identity for the same
        // placement. Two active instances of one Flash class cannot occupy
        // the same anchor; retain the newest state (for example, the seeded
        // plot rather than its older empty copy).
        $stackedGroups = DB::table('world_objects')
            ->select('world_id', 'class_name', 'position_x', 'position_y', 'position_z')
            ->where('deleted', false)
            ->whereNotNull('position_x')
            ->whereNotNull('position_y')
            ->groupBy('world_id', 'class_name', 'position_x', 'position_y', 'position_z')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($stackedGroups as $group) {
            $rows = DB::table('world_objects')
                ->where('world_id', $group->world_id)
                ->where('class_name', $group->class_name)
                ->where('position_x', $group->position_x)
                ->where('position_y', $group->position_y)
                ->where('position_z', $group->position_z)
                ->where('deleted', false)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id']);

            $keepId = $rows->shift()->id;
            DB::table('world_objects')->whereIn('id', $rows->pluck('id'))->delete();
        }

        Schema::table('world_objects', function (Blueprint $table) {
            $table->unique(['world_id', 'object_id'], 'world_objects_world_object_unique');
        });
    }

    public function down(): void
    {
        Schema::table('world_objects', function (Blueprint $table) {
            $table->dropUnique('world_objects_world_object_unique');
        });
    }
};
