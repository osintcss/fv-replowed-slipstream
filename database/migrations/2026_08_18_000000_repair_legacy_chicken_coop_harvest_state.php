<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FFDec: HarvestableStorageBuilding only permits a full Coop to be
        // clicked when it is in the ripe `grown` state. Older server rows
        // retained the animals in contents but serialized the building as
        // `built`, a state with no harvest interaction. These are existing,
        // already-populated coops whose missing timer cannot be recovered,
        // so make them immediately collectible once. Future harvests already
        // reset them to `bare` with a new plant_time timer.
        $query = DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'like', '%chickencoop%')
            ->where('state', 'built')
            ->whereNotNull('contents');

        // MariaDB and SQLite expose the same JSON array operation under
        // different names. Keeping this migration portable lets the feature
        // suite run against its in-memory SQLite database without changing
        // the production repair criteria.
        $jsonLengthFunction = DB::getDriverName() === 'sqlite'
            ? 'json_array_length'
            : 'JSON_LENGTH';

        $query->whereRaw("{$jsonLengthFunction}(contents) > 0")
            ->update([
                'state' => 'grown',
                'plant_time' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The prior `built` state is invalid for a Coop with animals inside;
        // do not restore it during rollback.
    }
};
