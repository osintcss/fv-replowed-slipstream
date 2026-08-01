<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserAdmin extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'user:admin {email : The registered account email address} {--revoke : Remove administrator access}';

    /**
     * The console command description.
     */
    protected $description = 'Grant or revoke administrator access for a registered account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No account exists for {$email}.");

            return self::FAILURE;
        }

        $isAdmin = ! $this->option('revoke');
        $user->forceFill(['is_admin' => $isAdmin])->save();

        $this->info($isAdmin
            ? "Administrator access granted to {$user->email}."
            : "Administrator access revoked for {$user->email}.");

        return self::SUCCESS;
    }
}
