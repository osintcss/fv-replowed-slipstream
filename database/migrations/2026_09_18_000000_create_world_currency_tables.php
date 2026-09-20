<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('currency_unit', 40);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('earned')->default(0);
            $table->unsignedBigInteger('purchased')->default(0);
            $table->timestamps();

            $table->unique(['uid', 'currency_unit']);
            $table->index(['uid', 'updated_at']);
        });

        Schema::create('world_currency_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20)->index();
            $table->string('currency_unit', 40);
            $table->string('source', 100);
            $table->bigInteger('delta');
            $table->unsignedBigInteger('balance');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['uid', 'currency_unit', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_currency_audits');
        Schema::dropIfExists('world_currencies');
    }
};
