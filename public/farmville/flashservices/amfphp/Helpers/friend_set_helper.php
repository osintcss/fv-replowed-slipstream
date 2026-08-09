<?php
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";

use App\Models\FriendSet;
use App\Support\Database;
use App\Helpers\JsonHelper;

function getFriendSet($uid, $code) {
    if (!is_numeric($uid)) return null;

    $fs = FriendSet::where('uid', $uid)
        ->where('code', $code)
        ->orderByDesc('fs_index')
        ->first();

    return $fs ? $fs->toArray() : null;
}

function getFriendSetByIndex($uid, $code, $fsIndex) {
    if (!is_numeric($uid)) return null;

    $fs = FriendSet::where('uid', $uid)
        ->where('code', $code)
        ->where('fs_index', $fsIndex)
        ->first();

    return $fs ? $fs->toArray() : null;
}

function createFriendSet($uid, $code, $worldCode, $totalRequired = 5) {
    if (!is_numeric($uid)) return null;

    $existing = getFriendSet($uid, $code);
    $nextIndex = $existing ? ((int) $existing['fs_index'] + 1) : 1;

    $neighbors = get_meta($uid, 'current_neighbors');
    $neighborUids = $neighbors ? (@unserialize($neighbors) ?: []) : [];

    $friends = new \stdClass();
    $count = 0;
    foreach ($neighborUids as $nUid) {
        if ($count >= $totalRequired) break;
        $friends->{"_" . $nUid} = "0";
        $count++;
    }

    $friendsJson = JsonHelper::safeEncode($friends);
    $pendingJson = JsonHelper::safeEncode([]);
    $startTime = time();

    FriendSet::create([
        'uid' => $uid,
        'code' => $code,
        'fs_index' => $nextIndex,
        'friends' => $friendsJson,
        'pending' => $pendingJson,
        'bought_count' => 0,
        'progress_state' => 0,
        'start_time' => $startTime,
        'world_code' => $worldCode,
        'reward_link' => '',
    ]);

    return getFriendSetByIndex($uid, $code, $nextIndex);
}

function updateProgressState($uid, $code, $fsIndex, $newState) {
    if (!is_numeric($uid)) return false;

    $affected = FriendSet::where('uid', $uid)
        ->where('code', $code)
        ->where('fs_index', $fsIndex)
        ->update(['progress_state' => $newState]);

    return $affected > 0;
}

function completeFriendSetWithCash($uid, $code, $fsIndex, $totalRequired = 5, $costPerFriend = 4) {
    $fs = getFriendSetByIndex($uid, $code, $fsIndex);
    if (!$fs) {
        return ["status" => 2, "cost" => 0, "data" => null, "gifts" => []];
    }

    $friends = JsonHelper::safeDecode($fs['friends'], true, []);
    $boughtCount = (int) $fs['bought_count'];
    $worldCode = $fs['world_code'] ?? "2dvd";
    $progressState = (int) ($fs['progress_state'] ?? 0);

    $item = getItemByCode($worldCode);
    $worldName = $item ? ($item['name'] ?? $worldCode) : $worldCode;

    $completedCount = 0;
    foreach ($friends as $val) {
        if ((int) $val > 0) $completedCount++;
    }
    $completedCount += $boughtCount;

    if ($completedCount >= $totalRequired) {
        if ($progressState < 2) {
            FriendSet::where('uid', $uid)
                ->where('code', $code)
                ->where('fs_index', $fsIndex)
                ->update(['progress_state' => 2]);

            addGiftByCode($uid, $worldCode);

            $updatedFs = getFriendSetByIndex($uid, $code, $fsIndex);
            return [
                "status" => 1,
                "cost" => 0,
                "data" => buildFriendSetResponse($updatedFs),
                "gifts" => [$worldName]
            ];
        }

        return [
            "status" => 2,
            "cost" => 0,
            "data" => buildFriendSetResponse($fs),
            "gifts" => []
        ];
    }

    $missing = $totalRequired - $completedCount;
    $cashCost = $missing * $costPerFriend;

    if (!UserResources::removeCash($uid, $cashCost)) {
        return ["status" => 2, "cost" => 0, "data" => buildFriendSetResponse($fs), "gifts" => []];
    }

    $newBought = $boughtCount + $missing;
    FriendSet::where('uid', $uid)
        ->where('code', $code)
        ->where('fs_index', $fsIndex)
        ->update([
            'bought_count' => $newBought,
            'progress_state' => 2,
        ]);

    $updatedFs = getFriendSetByIndex($uid, $code, $fsIndex);

    addGiftByCode($uid, $worldCode);

    return [
        "status" => 1,
        "cost" => $cashCost,
        "data" => buildFriendSetResponse($updatedFs),
        "gifts" => [$worldName]
    ];
}

function buildFriendSetResponse($row) {
    if (!$row) return [];

    $uids = JsonHelper::safeDecode($row['friends'], true, []);
    if (empty($uids)) $uids = new \stdClass();

    $pending = JsonHelper::safeDecode($row['pending'], true, []);

    return [
        "uids"              => $uids,
        "pending"           => $pending,
        "boughtFriendCount" => (int) $row['bought_count'],
        "startTime"         => (int) $row['start_time'],
        "rewardLink"        => $row['reward_link'] ?? "",
    ];
}

function recordFriendHelp($hostUid, $helperUid, $code = "FS06", $totalRequired = 5) {
    if (!is_numeric($hostUid) || !is_numeric($helperUid)) return false;
    if ($hostUid == $helperUid) return false;

    return Database::transaction('record friend-set help', static function () use ($hostUid, $helperUid, $code, $totalRequired) {
        $fs = FriendSet::where('uid', $hostUid)
            ->where('code', $code)
            ->where('progress_state', '<', 2)
            ->orderByDesc('fs_index')
            ->lockForUpdate()
            ->first();

        if (!$fs) return false;

        $friends = JsonHelper::safeDecode($fs->friends, true, []);
        $boughtCount = (int) $fs->bought_count;
        $helperKey = "_" . $helperUid;

        if (isset($friends[$helperKey]) && (int) $friends[$helperKey] > 0) {
            return false;
        }

        $helpedCount = 0;
        foreach ($friends as $val) {
            if ((int) $val > 0) $helpedCount++;
        }

        $totalCompleted = $helpedCount + $boughtCount;
        if ($totalCompleted >= $totalRequired) {
            return false;
        }

        $friends[$helperKey] = "1";
        $helpedCount++;
        $totalCompleted++;

        $fs->friends = JsonHelper::safeEncode($friends);

        if ($totalCompleted >= $totalRequired) {
            $fs->progress_state = 2;
            $fs->save();

            $worldCode = $fs->world_code ?? "2dvd";
            addGiftByCode($hostUid, $worldCode);

            Logger::debug('FriendSet', "Friend set completed by neighbor help: hostUid=$hostUid, helperUid=$helperUid, worldCode=$worldCode");
        } else {
            $fs->save();
        }

        Logger::debug('FriendSet', "Friend help recorded: hostUid=$hostUid, helperUid=$helperUid, code=$code, fsIndex={$fs->fs_index}, progress=$totalCompleted/$totalRequired");

        return true;
    });
}
