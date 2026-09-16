<?php

use App\Models\PlayerMeta;
use App\Support\WorldScoreConfig;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Functions/UserService.php';
});

function worldScoreTestPlayer(string $uid): object
{
    return new class($uid) {
        public function __construct(private string $uid)
        {
        }

        public function getUid(): string
        {
            return $this->uid;
        }
    };
}

it('does not let an out-of-order world-score update lower saved progress', function (): void {
    $uid = '940001';

    PlayerMeta::query()->create([
        'uid' => $uid,
        'meta_key' => 'world_score_sleepyhollow',
        'meta_value' => '120',
    ]);
    PlayerMeta::query()->create([
        'uid' => $uid,
        'meta_key' => 'world_score_level_sleepyhollow',
        'meta_value' => '2',
    ]);

    $request = (object) [
        'params' => ['sleepyhollowPoints', 1, 80],
    ];

    $response = UserService::updateWorldScoreLevelUp(
        worldScoreTestPlayer($uid),
        $request,
    );

    expect($response['data']['success'])->toBeTrue();
    $this->assertDatabaseHas('playermeta', [
        'uid' => $uid,
        'meta_key' => 'world_score_sleepyhollow',
        'meta_value' => '120',
    ]);
    $this->assertDatabaseHas('playermeta', [
        'uid' => $uid,
        'meta_key' => 'world_score_level_sleepyhollow',
        'meta_value' => (string) WorldScoreConfig::levelForScore('sleepyhollowPoints', 120),
    ]);
});

it('keeps legacy score-only world-score calls read-only', function (): void {
    $uid = '940002';

    PlayerMeta::query()->create([
        'uid' => $uid,
        'meta_key' => 'world_score_sleepyhollow',
        'meta_value' => '120',
    ]);
    PlayerMeta::query()->create([
        'uid' => $uid,
        'meta_key' => 'world_score_level_sleepyhollow',
        'meta_value' => '2',
    ]);

    UserService::updateWorldScoreLevelUp(
        worldScoreTestPlayer($uid),
        (object) ['params' => ['sleepyhollowPoints']],
    );

    $this->assertDatabaseHas('playermeta', [
        'uid' => $uid,
        'meta_key' => 'world_score_sleepyhollow',
        'meta_value' => '120',
    ]);
    $this->assertDatabaseHas('playermeta', [
        'uid' => $uid,
        'meta_key' => 'world_score_level_sleepyhollow',
        'meta_value' => '2',
    ]);
});
