<?php

use App\Models\PlayerMeta;
use App\Models\Quest;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/quest_helper.php';
    require_once AMFPHP_ROOTPATH.'Functions/FarmQuestService.php';
});

function questStateDefinition(string $name, array $tasks, array $children = [], array $prereqs = []): void
{
    Quest::query()->create([
        'name' => $name,
        'category' => 'story',
        'replay' => true,
        'tasks' => json_encode($tasks),
        'prereqs' => json_encode($prereqs),
        'children' => json_encode($children),
        'rewards' => json_encode([]),
        'frontend' => json_encode([]),
    ]);
}

it('removes only stale dialog-only entries from an existing active quest list', function (): void {
    $uid = '9900001';
    questStateDefinition('legacy-intro', [['action' => 'viewDialog', 'total' => 1]]);
    questStateDefinition('active-objective', [['action' => 'harvestByCode', 'type' => 'crop', 'total' => 5]]);

    setActiveQuests($uid, [
        'legacy-intro' => ['progress' => [0], 'completed' => false],
        'active-objective' => ['progress' => [2], 'completed' => false],
    ]);

    expect(removeStaleViewDialogQuests($uid))->toBe(['legacy-intro'])
        ->and(getActiveQuestIds($uid))->toBe(['active-objective']);
});

it('does not start a seventh active quest', function (): void {
    $uid = '9900002';
    $active = [];
    for ($i = 1; $i <= MAX_ACTIVE_QUESTS; $i++) {
        $name = "active-$i";
        questStateDefinition($name, [['action' => 'harvestByCode', 'type' => 'crop', 'total' => 1]]);
        $active[$name] = ['progress' => [0], 'completed' => false];
    }
    questStateDefinition('overflow-quest', [['action' => 'harvestByCode', 'type' => 'crop', 'total' => 1]]);
    setActiveQuests($uid, $active);

    expect(startQuestIfEligible($uid, 'overflow-quest', 99))->toBeNull()
        ->and(getActiveQuestIds($uid))->toHaveCount(MAX_ACTIVE_QUESTS);
});

it('ends a replayable quest chain and persists the client-selected removal', function (): void {
    $uid = '9900003';
    $root = 'replay-root-end-test';
    $child = 'replay-child-end-test';

    questStateDefinition($root, [['action' => 'viewDialog', 'total' => 1]], [
        ['type' => 'Quest', 'value' => $child],
    ]);
    questStateDefinition($child, [['action' => 'harvestByCode', 'type' => 'crop', 'total' => 5]], [], [
        ['type' => 'quest_complete', 'value' => $root],
    ]);
    questStateDefinition('unrelated-active-test', [['action' => 'harvestByCode', 'type' => 'crop', 'total' => 5]]);

    setActiveQuests($uid, [
        $child => ['progress' => [2], 'completed' => false],
        'unrelated-active-test' => ['progress' => [1], 'completed' => false],
    ]);

    $player = new class($uid) {
        public function __construct(private string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };

    $result = FarmQuestService::questManagerEndReplayableQuestChain(
        $player,
        (object) ['params' => [$root]],
        null
    );

    expect($result['data']['success'])->toBeTrue()
        ->and($result['data']['removedQuests'])->toBe([$child])
        ->and(getActiveQuestIds($uid))->toBe(['unrelated-active-test'])
        ->and($result['data']['quests'])->toHaveCount(1);
});
