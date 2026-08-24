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
        // SQLite does not support MariaDB's `ALTER TABLE ... MODIFY` syntax.
        // Its fresh test schema already creates both columns at this width.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE crafting_recipe_states MODIFY recipe_id VARCHAR(100) NOT NULL');
        DB::statement('ALTER TABLE crafting_queue MODIFY recipe_id VARCHAR(100) NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE crafting_recipe_states MODIFY recipe_id VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE crafting_queue MODIFY recipe_id VARCHAR(20) NOT NULL');
    }
};
