<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // `flower_garden` is the store placeholder, not a renderable Bloom
        // Garden. Preserve the player's flowers, featured slots, and upgrade
        // progress while changing only the client-facing item identity.
        DB::table('world_objects')
            ->where('deleted', false)
            ->where('item_name', 'flower_garden')
            ->where('class_name', 'FeatureBuilding')
            ->where(static function ($query): void {
                $query->whereNull('state')
                    ->orWhere('state', '!=', 'construction');
            })
            ->update([
                'item_name' => 'flower_garden_finished',
                'state' => DB::raw("CASE WHEN state IN ('grown', '') OR state IS NULL THEN 'bare' ELSE state END"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The finished name is canonical. Do not reintroduce the invisible
        // store placeholder into existing farms.
    }
};
