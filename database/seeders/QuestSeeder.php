<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestSeeder extends Seeder
{
    /**
     * Import quests from questSettings XML file into database.
     */
    public function run(): void
    {
        $xmlPath = public_path('farmville/xml/gz/v855038/questSettings_0.xml.gz');

        if (!file_exists($xmlPath)) {
            $this->command->error("Quest settings file not found: {$xmlPath}");
            return;
        }

        // Read and decompress the XML
        $compressed = file_get_contents($xmlPath);
        $xmlContent = @zlib_decode($compressed);

        if ($xmlContent === false) {
            $this->command->error("Failed to decompress quest settings file");
            return;
        }

        // Parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $this->command->error("Failed to parse quest settings XML");
            foreach (libxml_get_errors() as $error) {
                $this->command->error($error->message);
            }
            return;
        }

        $questCount = 0;
        $quests = [];

        foreach ($xml->quest as $quest) {
            $questData = $this->parseQuest($quest);
            if ($questData) {
                $quests[] = $questData;
                $questCount++;
            }
        }

        // Clear existing quests and insert new ones
        DB::table('quests')->truncate();

        // Insert in chunks to avoid memory issues
        foreach (array_chunk($quests, 100) as $chunk) {
            DB::table('quests')->insert($chunk);
        }

        $this->command->info("Imported {$questCount} quests successfully.");
    }

    /**
     * Parse a single quest XML element into database format.
     */
    private function parseQuest(\SimpleXMLElement $quest): ?array
    {
        $attrs = $quest->attributes();
        $name = (string) $attrs['name'];

        if (empty($name)) {
            return null;
        }

        // Parse prerequisites
        $prereqs = [];
        if (isset($quest->prereqs)) {
            foreach ($quest->prereqs->prereq as $prereq) {
                $prereqAttrs = $prereq->attributes();
                $prereqs[] = [
                    'type' => (string) $prereqAttrs['type'],
                    'value' => (string) $prereqAttrs['value'],
                ];
            }
        }

        // Parse children (next quests in chain)
        $children = [];
        if (isset($quest->children)) {
            foreach ($quest->children->child as $child) {
                $childAttrs = $child->attributes();
                $children[] = [
                    'type' => (string) $childAttrs['type'],
                    'value' => (string) $childAttrs['value'],
                ];
            }
        }

        // Parse tasks
        $tasks = [];
        if (isset($quest->tasks)) {
            foreach ($quest->tasks->task as $task) {
                $taskAttrs = $task->attributes();
                // Keep every task attribute.  Legacy `harvestBySubtype`
                // tasks store their real category in `subtype`, not `type`.
                $taskData = [];
                foreach ($taskAttrs as $key => $value) {
                    $taskData[(string) $key] = (string) $value;
                }
                $taskData['total'] = (int) ($taskData['total'] ?? 1) ?: 1;
                if (isset($taskData['cashValue'])) {
                    $taskData['cashValue'] = (int) $taskData['cashValue'];
                }
                if (isset($taskData['sticky'])) {
                    $taskData['sticky'] = $taskData['sticky'] === 'true';
                }

                $tasks[] = $taskData;
            }
        }

        // Parse rewards
        $rewards = [];
        if (isset($quest->rewards)) {
            foreach ($quest->rewards->reward as $reward) {
                $rewardAttrs = $reward->attributes();
                $rewardData = [
                    'type' => (string) $rewardAttrs['type'],
                    'value' => (string) $rewardAttrs['value'],
                ];

                if (isset($rewardAttrs['quantity'])) {
                    $rewardData['quantity'] = (int) $rewardAttrs['quantity'];
                }

                $rewards[] = $rewardData;
            }
        }

        // Parse frontend data
        $frontend = [];
        if (isset($quest->frontend)) {
            $fe = $quest->frontend;
            if (isset($fe->avatarHeadImage)) {
                $frontend['avatarHeadImage'] = (string) $fe->avatarHeadImage;
            }
            if (isset($fe->avatarBodyImage)) {
                $frontend['avatarBodyImage'] = (string) $fe->avatarBodyImage;
            }
            if (isset($fe->textBundle)) {
                $frontend['textBundle'] = (string) $fe->textBundle;
            }
            if (isset($fe->icon)) {
                $frontend['icon'] = (string) $fe->icon;
            }
            if (isset($fe->completeShareFeedIcon)) {
                $frontend['completeShareFeedIcon'] = (string) $fe->completeShareFeedIcon;
            }
            if (isset($fe->rewardView)) {
                $frontend['rewardView'] = (string) $fe->rewardView;
            }
        }

        // Parse friend reward
        $friendReward = null;
        if (isset($quest->friendReward)) {
            $frAttrs = $quest->friendReward->attributes();
            $friendReward = [
                'bundle' => (string) $frAttrs['bundle'],
                'item' => (string) $frAttrs['item'],
            ];
        }

        return [
            'name' => $name,
            'category' => (string) ($attrs['category'] ?? 'story'),
            'priority' => (int) ($attrs['priority'] ?? 1),
            'replay' => ((string) ($attrs['replay'] ?? 'false')) === 'true',
            'skip' => ((string) ($attrs['skip'] ?? 'false')) === 'true',
            'kill_quest' => ((string) ($attrs['kill'] ?? 'false')) === 'true',
            'mem_store_id' => !empty($attrs['memStoreId']) ? (int) $attrs['memStoreId'] : null,
            'prereqs' => json_encode($prereqs),
            'children' => json_encode($children),
            'tasks' => json_encode($tasks),
            'rewards' => json_encode($rewards),
            'frontend' => json_encode($frontend),
            'friend_reward' => $friendReward ? json_encode($friendReward) : null,
        ];
    }
}
