<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('usermeta')
            ->where('profile_picture', 'like', '%/profile-pictures/discord/%')
            ->where('profile_picture', 'not like', '%?v=2')
            ->update([
                'profile_picture' => DB::raw("CONCAT(profile_picture, '?v=2')"),
            ]);
    }

    public function down(): void
    {
        DB::table('usermeta')
            ->where('profile_picture', 'like', '%?v=2')
            ->update([
                'profile_picture' => DB::raw("REPLACE(profile_picture, '?v=2', '')"),
            ]);
    }
};
