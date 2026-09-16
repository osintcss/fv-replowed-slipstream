<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_action_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('action', 50);
            $table->string('request_key', 64);
            $table->text('response');
            $table->timestamps();

            $table->unique(['uid', 'action', 'request_key']);
            $table->index(['uid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_action_receipts');
    }
};
