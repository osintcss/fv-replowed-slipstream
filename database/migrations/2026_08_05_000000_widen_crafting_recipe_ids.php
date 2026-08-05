<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archived crafting catalog contains descriptive recipe IDs longer
     * than the old 20-character field (for example part_xyt_christmasbarrel).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE crafting_recipe_states MODIFY recipe_id VARCHAR(100) NOT NULL');
        DB::statement('ALTER TABLE crafting_queue MODIFY recipe_id VARCHAR(100) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE crafting_recipe_states MODIFY recipe_id VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE crafting_queue MODIFY recipe_id VARCHAR(20) NOT NULL');
    }
};
