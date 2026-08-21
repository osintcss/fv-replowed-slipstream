<?php

namespace App\Console\Commands;

use App\Models\Quest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncQuestTaskAttributes extends Command
{
    protected $signature = 'quest:sync-task-attributes {--dry-run : Report changes without saving them}';

    protected $description = 'Restore task attributes omitted by earlier quest imports, including harvest subtypes';

    public function handle(): int
    {
        $path = public_path('farmville/xml/gz/v855038/questSettings_0.xml.gz');
        $xmlContent = @gzuncompress((string) file_get_contents($path));
        $xml = $xmlContent === false ? false : @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->error('Unable to read the quest settings archive.');
            return self::FAILURE;
        }

        $changed = 0;
        foreach ($xml->quest as $xmlQuest) {
            $name = (string) $xmlQuest['name'];
            $quest = Quest::where('name', $name)->first();
            if (!$quest || !isset($xmlQuest->tasks)) {
                continue;
            }

            $tasks = json_decode($quest->tasks ?: '[]', true);
            if (!is_array($tasks)) {
                continue;
            }
            $updated = false;
            $index = 0;
            foreach ($xmlQuest->tasks->task as $xmlTask) {
                if (!isset($tasks[$index])) {
                    $index++;
                    continue;
                }
                foreach ($xmlTask->attributes() as $key => $value) {
                    $key = (string) $key;
                    $value = match ($key) {
                        'total', 'cashValue' => (int) $value,
                        'sticky' => (string) $value === 'true',
                        default => (string) $value,
                    };
                    if (($tasks[$index][$key] ?? null) !== $value) {
                        $tasks[$index][$key] = $value;
                        $updated = true;
                    }
                }
                $index++;
            }
            if (!$updated) {
                continue;
            }

            $changed++;
            if (!$this->option('dry-run')) {
                // Quest deliberately restricts mass assignment to its legacy
                // payload fields, so write this parsed-column repair directly.
                DB::table('quests')->where('id', $quest->id)->update([
                    'tasks' => json_encode($tasks, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would update' : 'Updated')." {$changed} quest definitions.");
        return self::SUCCESS;
    }
}
