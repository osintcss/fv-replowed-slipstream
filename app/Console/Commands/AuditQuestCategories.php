<?php

namespace App\Console\Commands;

use App\Models\Quest;
use App\Support\QuestCategoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditQuestCategories extends Command
{
    protected $signature = 'quest:audit-categories {--strict : Exit unsuccessfully when required categories have no producer}';

    protected $description = 'Report quest category requirements that no imported item or feature building can produce';

    public function handle(): int
    {
        try {
            $this->line('Reading quest category contracts...');
            $required = [];
        foreach (Quest::query()->select(['name', 'tasks'])->cursor() as $quest) {
            $tasks = json_decode($quest->tasks ?: '[]', true);
            if (! is_array($tasks)) {
                $this->warn("Ignoring malformed tasks for quest {$quest->name}.");
                continue;
            }
            foreach ($tasks as $task) {
                $action = $task['action'] ?? null;
                $type = $task['type'] ?? null;
                if (! is_string($action) || ! str_ends_with($action, 'ByCategory') || ! is_string($type) || $type === '') {
                    continue;
                }
                $category = QuestCategoryResolver::taskCategory($type);
                $key = QuestCategoryResolver::normalized($category);
                $required[$key] ??= ['category' => $category, 'actions' => [], 'quests' => []];
                $required[$key]['actions'][$action] = true;
                $required[$key]['quests'][$quest->name] = true;
            }
        }

        $this->line('Reading imported item category producers...');
        $producers = [];
        foreach (DB::table('items')->select(['name', 'data'])->orderBy('id')->cursor() as $item) {
            $itemName = (string) ($item->name ?? '');
            // Item definitions are legacy PHP-serialized blobs. This audit
            // must tolerate any historical blob, so it extracts the scalar
            // category fields without executing a deserializer. Runtime
            // category aliases still come from the shared resolver below.
            $serializedCategories = [];
            $rawData = $item->data ?? null;
            if (is_string($rawData)) {
                preg_match_all(
                    '/s:\\d+:"(?:categories|category|subtype)";s:\\d+:"([^"]*)";/',
                    $rawData,
                    $matches,
                );
                $serializedCategories = $matches[1] ?? [];
            }
            $categories = array_merge(
                [$itemName],
                $serializedCategories,
                QuestCategoryResolver::categories($itemName),
            );
            foreach ($categories as $category) {
                $key = QuestCategoryResolver::normalized($category);
                if ($key !== '') {
                    $producers[$key] = true;
                }
            }
        }

        $missing = [];
        foreach ($required as $key => $contract) {
            if (! isset($producers[$key])) {
                $missing[] = [$contract['category'], implode(', ', array_keys($contract['actions'])), implode(', ', array_slice(array_keys($contract['quests']), 0, 5))];
            }
        }

        $this->info(sprintf('Audited %d quest category requirement(s) against %d producible category identifier(s).', count($required), count($producers)));
        if ($missing === []) {
            $this->info('No missing quest category producers found.');
            return self::SUCCESS;
        }

        $this->table(['Missing category', 'Quest actions', 'Example quests'], $missing);
        $this->warn(count($missing).' category requirement(s) need review. No aliases were created automatically.');
            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Quest category audit failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }
    }
}
