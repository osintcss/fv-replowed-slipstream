<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('usermeta')
            ->whereNotNull('profile_picture')
            ->where('profile_picture', 'not like', 'https://cdn.discordapp.com/%')
            ->update(['profile_picture' => null]);
    }

    public function down(): void
    {
        // Removed uploads cannot be reliably restored.
    }
};
