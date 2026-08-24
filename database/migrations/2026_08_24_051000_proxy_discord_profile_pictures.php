<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_identities', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable()->after('discord_id');
        });

        DB::table('discord_identities')
            ->join('users', 'users.id', '=', 'discord_identities.user_id')
            ->join('usermeta', 'usermeta.uid', '=', 'users.uid')
            ->where('usermeta.profile_picture', 'like', 'https://cdn.discordapp.com/%')
            ->select('discord_identities.id', 'usermeta.profile_picture')
            ->orderBy('discord_identities.id')
            ->each(function (object $identity): void {
                DB::table('discord_identities')
                    ->where('id', $identity->id)
                    ->update(['avatar_url' => $identity->profile_picture]);
            });

        $baseUrl = rtrim((string) config('app.url'), '/');

        DB::table('usermeta')
            ->where('profile_picture', 'like', 'https://cdn.discordapp.com/%')
            ->update([
                'profile_picture' => DB::raw("CONCAT('{$baseUrl}/profile-pictures/discord/', uid)"),
            ]);
    }

    public function down(): void
    {
        Schema::table('discord_identities', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
