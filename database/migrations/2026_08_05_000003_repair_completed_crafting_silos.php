<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Older market placements persisted the generic construction state.
        // The shipped Flash client only recognizes a completed Crafting Silo
        // as a valid ingredient store for Craftshop recipes.
        DB::table('world_objects')
            ->where('item_name', 'craftingsilo')
            ->where('deleted', false)
            ->where(function ($query) {
                $query->whereNull('state')
                    ->orWhere('state', '')
                    ->orWhere('state', 'bare');
            })
            ->update([
                'state' => 'grown',
                'expansion_level' => DB::raw('GREATEST(COALESCE(expansion_level, 0), 1)'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not revert a completed Silo to the invalid generic state.
    }
};
