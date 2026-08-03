<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerExportController extends Controller
{
    /**
     * Download the authenticated player's portable game save.
     *
     * Raw serialized values are retained alongside decoded values because the
     * Flash service still uses PHP serialization for several inventories and
     * feature states. That makes the export lossless and suitable for a later
     * import tool without exposing the account password or session data.
     */
    public function download(Request $request): StreamedResponse
    {
        $user = $request->user();
        $uid = (string) $user->uid;

        $playerMeta = DB::table('playermeta')
            ->where('uid', $uid)
            ->orderBy('meta_key')
            ->get(['meta_key', 'meta_value'])
            ->mapWithKeys(function ($entry) {
                return [$entry->meta_key => [
                    'raw' => $entry->meta_value,
                    'decoded' => $this->decodeSerializedValue($entry->meta_value),
                ]];
            })
            ->all();

        $worlds = DB::table('userworlds')
            ->where('uid', $uid)
            ->orderBy('type')
            ->get()
            ->map(function ($world) {
                $worldData = (array) $world;
                // `userworlds.objects` is a legacy serialized column. Keep it
                // distinct from the normalized world-object list below so an
                // import can restore both representations correctly.
                $worldData['objects_legacy'] = $worldData['objects'] ?? null;
                $worldData['objects'] = DB::table('world_objects')
                    ->where('world_id', $world->id)
                    ->orderBy('object_id')
                    ->get()
                    ->map(fn ($object) => (array) $object)
                    ->all();

                return $worldData;
            })
            ->all();

        $export = [
            'format' => 'fv-replowed-slipstream-player-export',
            'version' => 1,
            'exportedAt' => now()->toIso8601String(),
            'player' => [
                'uid' => $uid,
                'name' => $user->name,
                'email' => $user->email,
                'createdAt' => optional($user->created_at)->toIso8601String(),
            ],
            'gameData' => [
                'userMeta' => $this->firstRow('usermeta', $uid),
                'avatar' => $this->serializedRow('useravatars', $uid, 'value'),
                'playerMeta' => $playerMeta,
                'storage' => [
                    'giftBox' => $playerMeta['giftbox']['decoded'] ?? [],
                    'inventory' => $playerMeta['inventory_storage']['decoded'] ?? [],
                ],
                'worlds' => $worlds,
                'craftingInventory' => $this->rowsForUser('crafting_inventory', $uid),
                'craftingQueue' => $this->rowsForUser('crafting_queue', $uid),
                'craftingSkills' => $this->rowsForUser('crafting_skills', $uid),
                'craftingRecipeStates' => $this->rowsForUser('crafting_recipe_states', $uid),
                'marketStalls' => $this->rowsForUser('market_stalls', $uid),
                'dailyGifts' => $this->rowsForUser('daily_gifts', $uid),
                'friendSets' => $this->rowsForUser('friend_sets', $uid),
                'avatarUnlocks' => $this->rowsForUser('avatar_unlocks', $uid),
            ],
        ];

        $date = now()->format('Y-m-d_His');
        $filename = "farmville-save-{$uid}-{$date}.json";

        return response()->streamDownload(function () use ($export) {
            echo json_encode(
                $export,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        }, $filename, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function firstRow(string $table, string $uid): ?array
    {
        $row = DB::table($table)->where('uid', $uid)->first();

        return $row ? (array) $row : null;
    }

    private function rowsForUser(string $table, string $uid): array
    {
        return DB::table($table)
            ->where('uid', $uid)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function serializedRow(string $table, string $uid, string $column): ?array
    {
        $row = $this->firstRow($table, $uid);
        if ($row === null) {
            return null;
        }

        $raw = $row[$column] ?? null;
        $row[$column] = [
            'raw' => $raw,
            'decoded' => is_string($raw) ? $this->decodeSerializedValue($raw) : null,
        ];

        return $row;
    }

    private function decodeSerializedValue(string $value): mixed
    {
        $decoded = @unserialize($value, ['allowed_classes' => false]);

        // `unserialize` returns false both for invalid input and serialized
        // false. Preserve the original string in either case; only expose a
        // decoded value when it was genuinely encoded data.
        if ($decoded === false && $value !== serialize(false)) {
            return null;
        }

        return $decoded;
    }
}
