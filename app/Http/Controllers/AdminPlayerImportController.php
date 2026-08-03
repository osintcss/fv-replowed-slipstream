<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

class AdminPlayerImportController extends Controller
{
    private const FORMAT = 'fv-replowed-slipstream-player-export';
    private const VERSION = 1;

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'save_file' => ['required', 'file', 'mimes:json,txt', 'max:51200'],
            'replace_existing_save' => ['accepted'],
        ]);

        try {
            $contents = file_get_contents($request->file('save_file')->getRealPath());
            if ($contents === false) {
                throw new JsonException('Unable to read upload.');
            }
            $export = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return back()->withErrors(['save_file' => 'The upload is not valid JSON.']);
        }

        if (($export['format'] ?? null) !== self::FORMAT || ($export['version'] ?? null) !== self::VERSION) {
            return back()->withErrors(['save_file' => 'This is not a supported FarmVille save export.']);
        }

        $uid = (string) ($export['player']['uid'] ?? '');
        $gameData = $export['gameData'] ?? null;
        if (!preg_match('/^\d{1,20}$/', $uid) || !is_array($gameData)) {
            return back()->withErrors(['save_file' => 'The export is missing a valid player UID or game-data section.']);
        }

        if (!User::where('uid', $uid)->exists()) {
            return back()->withErrors(['save_file' => "No existing account has UID {$uid}. Create the account before importing its save."]);
        }

        try {
            DB::transaction(function () use ($uid, $gameData) {
                $this->replaceUserMeta($uid, $gameData['userMeta'] ?? null);
                $this->replaceAvatar($uid, $gameData['avatar'] ?? null);
                $this->replacePlayerMeta($uid, $gameData['playerMeta'] ?? []);
                $this->replaceWorlds($uid, $gameData['worlds'] ?? []);

                foreach ([
                    'crafting_inventory' => 'craftingInventory',
                    'crafting_queue' => 'craftingQueue',
                    'crafting_skills' => 'craftingSkills',
                    'crafting_recipe_states' => 'craftingRecipeStates',
                    'market_stalls' => 'marketStalls',
                    'daily_gifts' => 'dailyGifts',
                    'friend_sets' => 'friendSets',
                    'avatar_unlocks' => 'avatarUnlocks',
                ] as $table => $exportKey) {
                    $this->replaceRows($table, $uid, $gameData[$exportKey] ?? []);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Admin save import failed', ['uid' => $uid, 'error' => $e->getMessage()]);
            return back()->withErrors(['save_file' => 'The save could not be restored; no changes were applied.']);
        }

        Log::notice('Admin restored player save', [
            'admin_uid' => $request->user()->uid,
            'target_uid' => $uid,
        ]);

        return back()->with('status', "Save restored for UID {$uid}.");
    }

    private function replaceUserMeta(string $uid, mixed $row): void
    {
        if (!is_array($row)) {
            return;
        }

        $allowed = array_flip([
            'firstName', 'lastName', 'profile_picture', 'xp', 'cash', 'gold',
            'energyMax', 'energy', 'seenFlags', 'isNew', 'firstDay',
        ]);
        $data = array_intersect_key($row, $allowed);
        if ($data !== []) {
            DB::table('usermeta')->updateOrInsert(['uid' => $uid], $data);
        }
    }

    private function replaceAvatar(string $uid, mixed $avatar): void
    {
        if (!is_array($avatar)) {
            return;
        }

        $raw = $avatar['value']['raw'] ?? null;
        if (!is_string($raw)) {
            return;
        }

        DB::table('useravatars')->updateOrInsert(['uid' => $uid], ['value' => $raw]);
    }

    private function replacePlayerMeta(string $uid, mixed $playerMeta): void
    {
        if (!is_array($playerMeta)) {
            throw new \InvalidArgumentException('Invalid player metadata.');
        }

        DB::table('playermeta')->where('uid', $uid)->delete();
        $rows = [];
        foreach ($playerMeta as $key => $entry) {
            $raw = is_array($entry) ? ($entry['raw'] ?? null) : null;
            if (!is_string($key) || $key === '' || strlen($key) > 255 || !is_string($raw)) {
                throw new \InvalidArgumentException('Invalid player metadata entry.');
            }
            $rows[] = ['uid' => $uid, 'meta_key' => $key, 'meta_value' => $raw];
        }

        if ($rows !== []) {
            DB::table('playermeta')->insert($rows);
        }
    }

    private function replaceWorlds(string $uid, mixed $worlds): void
    {
        if (!is_array($worlds)) {
            throw new \InvalidArgumentException('Invalid worlds data.');
        }

        $existingWorldIds = DB::table('userworlds')->where('uid', $uid)->pluck('id');
        if ($existingWorldIds->isNotEmpty()) {
            DB::table('world_objects')->whereIn('world_id', $existingWorldIds)->delete();
        }
        DB::table('userworlds')->where('uid', $uid)->delete();

        foreach ($worlds as $world) {
            if (!is_array($world) || !isset($world['id']) || !is_string($world['type'] ?? null)) {
                throw new \InvalidArgumentException('Invalid world entry.');
            }

            $legacyObjects = $world['objects_legacy'] ?? null;
            if (!is_string($legacyObjects) && $legacyObjects !== null) {
                $legacyObjects = null;
            }

            $worldId = DB::table('userworlds')->insertGetId([
                'uid' => $uid,
                'type' => $world['type'],
                'sizeX' => (int) ($world['sizeX'] ?? 12),
                'sizeY' => (int) ($world['sizeY'] ?? 12),
                'objects' => $legacyObjects,
                'messageManager' => $world['messageManager'] ?? '',
            ]);

            $objects = $world['objects'] ?? [];
            if (!is_array($objects)) {
                throw new \InvalidArgumentException('Invalid world objects.');
            }
            $rows = [];
            foreach ($objects as $object) {
                if (!is_array($object)) {
                    throw new \InvalidArgumentException('Invalid world object.');
                }
                unset($object['id'], $object['world_id'], $object['created_at'], $object['updated_at']);
                $object['world_id'] = $worldId;
                $rows[] = $object;
            }
            if ($rows !== []) {
                DB::table('world_objects')->insert($rows);
            }
        }
    }

    private function replaceRows(string $table, string $uid, mixed $rows): void
    {
        if (!is_array($rows)) {
            throw new \InvalidArgumentException("Invalid {$table} data.");
        }

        DB::table($table)->where('uid', $uid)->delete();
        $insert = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException("Invalid {$table} row.");
            }
            unset($row['id'], $row['uid'], $row['created_at'], $row['updated_at']);
            $row['uid'] = $uid;
            $insert[] = $row;
        }
        if ($insert !== []) {
            DB::table($table)->insert($insert);
        }
    }
}
