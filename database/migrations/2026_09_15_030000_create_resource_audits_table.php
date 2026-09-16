<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uid')->index();
            $table->string('source', 100);
            $table->bigInteger('gold_delta');
            $table->bigInteger('xp_delta');
            $table->bigInteger('cash_delta');
            $table->unsignedBigInteger('gold_balance');
            $table->unsignedBigInteger('xp_balance');
            $table->unsignedBigInteger('cash_balance');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['uid', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_audits');
    }
};
