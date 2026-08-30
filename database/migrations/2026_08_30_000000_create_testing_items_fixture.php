<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The production items table is imported legacy game data rather than an
     * application-owned table. Tests still need its minimal lookup shape,
     * though, and this fixture must exist before RefreshDatabase starts its
     * per-test transaction.
     */
    public function up(): void
    {
        if (! app()->environment('testing') || Schema::hasTable('items')) {
            return;
        }

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->longText('data');
        });
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            Schema::dropIfExists('items');
        }
    }
};
