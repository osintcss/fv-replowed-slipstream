<?php

use App\Models\CraftingSkill;
use App\Models\User;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\CraftingCottages;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/crafting_helper.php';
});

it('initializes Porcelain Shop crafting state for an Emerald Valley cottage', function (): void {
    $uid = User::factory()->create()->uid;
    $world = UserWorld::query()->create([
        'uid' => (string) $uid,
        'type' => 'oz',
        'sizeX' => 12,
        'sizeY' => 12,
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);

    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 29,
        'class_name' => 'CraftingCottageBuilding',
        'item_name' => 'xozcraftingcottage',
        'position_x' => 1,
        'position_y' => 1,
        'position_z' => 0,
        'state' => 'built',
        'deleted' => false,
        'expansion_level' => 1,
    ]);

    $types = collect(getCraftingSkillState($uid)['craftTypes'])->keyBy('type');

    expect(CraftingCottages::craftTypeForItem('xozcraftingcottage'))->toBe('xozcrafttype')
        ->and(CraftingCottages::craftTypeForItem('xozcraftingshop_finished'))->toBe('craftshop')
        ->and($types->get('xozcrafttype'))->toBe([
            'type' => 'xozcrafttype',
            'level' => 1,
            'xp' => 0,
            'exp' => 0,
        ])
        ->and(CraftingSkill::query()
            ->where('uid', $uid)
            ->where('craft_type', 'xozcrafttype')
            ->value('level'))
        ->toBe(1);
});
