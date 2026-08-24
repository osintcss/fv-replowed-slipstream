<?php

namespace App\Support;

final class DiscordAvatar
{
    public static function url(string $discordId, ?string $avatarHash): string
    {
        if ($avatarHash !== null && $avatarHash !== '') {
            $extension = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';

            return "https://cdn.discordapp.com/avatars/{$discordId}/{$avatarHash}.{$extension}?size=128";
        }

        // Discord assigns one of six default avatars from the user's snowflake.
        $defaultAvatar = intdiv((int) $discordId, 4_194_304) % 6;

        return "https://cdn.discordapp.com/embed/avatars/{$defaultAvatar}.png";
    }
}
