<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crafting_queue', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20);
            $table->string('recipe_id', 100);
            $table->string('craft_type', 40);
            $table->integer('oven_slot')->default(-1);
            $table->integer('start_ts');
            $table->integer('finish_ts');
            $table->string('world_type', 20)->default('farm');
            $table->string('status', 10)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->index('uid');
        });

        Schema::create('crafting_inventory', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20);
            $table->string('item_code', 20);
            $table->integer('quantity')->default(0);
            $table->string('storage_type', 20)->default('silo');

            $table->unique(['uid', 'item_code', 'storage_type']);
        });

        Schema::create('crafting_skills', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20);
            $table->string('craft_type', 40);
            $table->integer('level')->default(1);
            $table->integer('xp')->default(0);

            $table->unique(['uid', 'craft_type']);
        });

        Schema::create('crafting_recipe_states', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 20);
            $table->string('recipe_id', 100);
            $table->integer('level')->default(1);
            $table->integer('xp')->default(0);
            $table->tinyInteger('is_unlocked')->default(1);

            $table->unique(['uid', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crafting_queue');
        Schema::dropIfExists('crafting_inventory');
        Schema::dropIfExists('crafting_skills');
        Schema::dropIfExists('crafting_recipe_states');
    }
};
