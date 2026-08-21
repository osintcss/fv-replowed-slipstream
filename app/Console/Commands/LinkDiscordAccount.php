<?php

namespace App\Console\Commands;

use App\Models\DiscordIdentity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkDiscordAccount extends Command
{
    protected $signature = 'discord:link-account {uid : Existing FarmVille UID} {discordId : Numeric Discord user ID} {--linked-by= : Internal user ID of the operator}';

    protected $description = 'Safely link an existing FarmVille account to a Discord identity';

    public function handle(): int
    {
        $uid = (string) $this->argument('uid');
        $discordId = (string) $this->argument('discordId');
        if (!preg_match('/^\d{15,25}$/', $discordId)) {
            $this->error('Discord ID must be a numeric Discord snowflake.');
            return self::FAILURE;
        }

        $user = User::where('uid', $uid)->first();
        if (!$user) {
            $this->error("FarmVille UID {$uid} was not found.");
            return self::FAILURE;
        }

        $linkedBy = $this->option('linked-by');
        if ($linkedBy !== null && !User::whereKey($linkedBy)->exists()) {
            $this->error("Operator user ID {$linkedBy} was not found.");
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($user, $discordId, $linkedBy): void {
                $existingDiscord = DiscordIdentity::where('discord_id', $discordId)->first();
                if ($existingDiscord && $existingDiscord->user_id !== $user->id) {
                    throw new \RuntimeException('That Discord ID is already linked to another FarmVille account.');
                }

                $existingUser = DiscordIdentity::where('user_id', $user->id)->first();
                if ($existingUser && $existingUser->discord_id !== $discordId) {
                    throw new \RuntimeException('That FarmVille account is already linked to a different Discord ID.');
                }

                DiscordIdentity::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'discord_id' => $discordId,
                        'linked_at' => now(),
                        'linked_by_user_id' => $linkedBy,
                    ],
                );
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Linked Discord ID {$discordId} to FarmVille UID {$uid}.");
        return self::SUCCESS;
    }
}
