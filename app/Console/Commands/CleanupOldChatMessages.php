<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupOldChatMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report that chat retention is disabled';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Chat retention is disabled; no messages deleted.');

        return 0;
    }
}
