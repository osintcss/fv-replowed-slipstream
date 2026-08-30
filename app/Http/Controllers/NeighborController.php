<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\PlayerMeta;

class NeighborController extends Controller
{
    private const AUTO_ACCEPT_META_KEY = 'auto_accept_neighbor_requests';

    public static function autoAcceptNeighborRequests(string|int $uid): bool
    {
        $value = PlayerMeta::getValue($uid, self::AUTO_ACCEPT_META_KEY);
        if ($value === false || trim($value) === '') {
            return true;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return true;
    }

    public static function setAutoAcceptNeighborRequests(string|int $uid, bool $enabled): void
    {
        PlayerMeta::setValue($uid, self::AUTO_ACCEPT_META_KEY, $enabled ? '1' : '0');
    }

    public function getNeighborsData()
    {
        $user = Auth::user();

        $currentNeighborsMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'current_neighbors')
            ->value('meta_value');
        
        $neighborIds = $currentNeighborsMeta ? unserialize($currentNeighborsMeta, ['allowed_classes' => false]) : [];
        
        $neighbors = [];
        
        if (!empty($neighborIds)) {
            $neighborsQuery = DB::table('users as u')
                ->leftJoin('usermeta as um', 'u.uid', '=', 'um.uid')
                ->leftJoin('useravatars as ua', 'u.uid', '=', 'ua.uid')
                ->whereIn('u.uid', $neighborIds)
                ->select(
                    'u.uid',
                    'u.name',
                    'um.firstName',
                    'um.lastName',
                    'um.profile_picture',
                    'ua.value as avatar_value'
                )
                ->get();

            $groupedNeighbors = [];
            foreach ($neighborsQuery as $row) {
                $avatarData = @unserialize($row->avatar_value, ['allowed_classes' => false]);
                $gender = is_array($avatarData) && isset($avatarData['gender'])
                    ? $avatarData['gender']
                    : 'male';

                if (!isset($groupedNeighbors[$row->uid])) {
                    $groupedNeighbors[$row->uid] = [
                        'uid' => $row->uid,
                        'name' => $row->name,
                        'first_name' => $row->firstName,
                        'last_name' => $row->lastName,
                        'sex' => $gender == 'male' ? 'm' : 'f',
                        'profile_picture' => $row->profile_picture,
                    ];
                }
            }

            foreach ($groupedNeighbors as $neighbor) {
                $pic = $neighbor['profile_picture'] ?: 'https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png';

                $neighbors[] = [
                    'uid' => (string) $neighbor['uid'],
                    'first_name' => $neighbor['first_name'] ?? '',
                    'last_name' => $neighbor['last_name'] ?? '',
                    'name' => $neighbor['name'] ?? '',
                    'pic' => $pic,
                    'pic_square' => $pic,
                    'sex' => $neighbor['sex'] ?? 'm',
                    'is_app_user' => true,
                ];
            }

        }

        return [
            'neighbors' => $neighbors,
            'neighborIds' => array_column($neighbors, 'uid'),
            'neighborsBase64' => base64_encode(json_encode($neighbors))
        ];
    }
    
    public function addNeighbor(Request $request)
    {
        $user = Auth::user();
        $neighborId = $request->input('neighbor_id');

        $neighborExists = DB::table('users')->where('uid', $neighborId)->exists();
        if (!$neighborExists) {
            return response()->json(['error' => 'Neighbor not found'], 404);
        }

        $currentNeighborsMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'current_neighbors')
            ->value('meta_value');
        
        $neighborIds = $currentNeighborsMeta ? unserialize($currentNeighborsMeta, ['allowed_classes' => false]) : [];

        if (!in_array($neighborId, $neighborIds)) {
            $neighborIds[] = $neighborId;

            $exists = DB::table('playermeta')
                ->where('uid', $user->uid)
                ->where('meta_key', 'current_neighbors')
                ->exists();
            
            if ($exists) {
                DB::table('playermeta')
                    ->where('uid', $user->uid)
                    ->where('meta_key', 'current_neighbors')
                    ->update(['meta_value' => serialize($neighborIds)]);
            } else {
                DB::table('playermeta')->insert([
                    'uid' => $user->uid,
                    'meta_key' => 'current_neighbors',
                    'meta_value' => serialize($neighborIds)
                ]);
            }
        }
        
        return response()->json(['success' => true, 'message' => 'Neighbor added successfully']);
    }
    
    public function removeNeighbor(Request $request)
    {
        $user = Auth::user();
        $neighborId = $request->input('neighbor_id');

        $currentNeighborsMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'current_neighbors')
            ->value('meta_value');

        $neighborIds = $currentNeighborsMeta ? unserialize($currentNeighborsMeta, ['allowed_classes' => false]) : [];
        $neighborIds = array_values(array_filter($neighborIds, fn($id) => $id != $neighborId));

        if (empty($neighborIds)) {
            DB::table('playermeta')
                ->where('uid', $user->uid)
                ->where('meta_key', 'current_neighbors')
                ->delete();
        } else {
            DB::table('playermeta')
                ->where('uid', $user->uid)
                ->where('meta_key', 'current_neighbors')
                ->update(['meta_value' => serialize($neighborIds)]);
        }

        return response()->json(['success' => true, 'message' => 'Neighbor removed successfully']);
    }
    
    public function getPotentialNeighbors()
    {
        $user = Auth::user();

        $currentNeighborsMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'current_neighbors')
            ->value('meta_value');

        $currentNeighborIds = $currentNeighborsMeta ? unserialize($currentNeighborsMeta, ['allowed_classes' => false]) : [];
        $currentNeighborIds[] = $user->uid;

        $potentialNeighbors = DB::table('users as u')
            ->join('usermeta as um', 'u.uid', '=', 'um.uid')
            ->join('useravatars as ua', 'u.uid', '=', 'ua.uid')
            ->whereNotIn('u.uid', $currentNeighborIds)
            ->select(
                'u.uid',
                'u.name',
                'um.firstName',
                'um.lastName',
                'ua.value as avatar_value'
            )
            ->get();

        $groupedUsers = [];
        foreach ($potentialNeighbors as $row) {
            $avatarData = @unserialize($row->avatar_value, ['allowed_classes' => false]);
            $gender = is_array($avatarData) && isset($avatarData['gender'])
                ? $avatarData['gender']
                : 'f';

            if (!isset($groupedUsers[$row->uid])) {
                $groupedUsers[$row->uid] = [
                    'uid' => $row->uid,
                    'name' => $row->name,
                    'first_name' => $row->firstName,
                    'last_name' => $row->lastName,
                    'sex' => $gender,
                ];
            }
        }
        
        return response()->json(['users' => array_values($groupedUsers)]);
    }

    public function getPendingRequests()
    {
        $user = Auth::user();

        $pendingNeighborsMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'pending_neighbors')
            ->value('meta_value');
        
        $pendingIds = $pendingNeighborsMeta ? unserialize($pendingNeighborsMeta, ['allowed_classes' => false]) : [];
        
        $pendingNeighbors = [];
        
        if (!empty($pendingIds)) {
            $neighborsQuery = DB::table('users as u')
                ->join('usermeta as um', 'u.uid', '=', 'um.uid')
                ->join('useravatars as ua', 'u.uid', '=', 'ua.uid')
                ->whereIn('u.uid', $pendingIds)
                ->select(
                    'u.uid',
                    'u.name',
                    'um.firstName',
                    'um.lastName',
                    'ua.value as avatar_value'
                )
                ->get();
            
            $groupedNeighbors = [];
            foreach ($neighborsQuery as $row) {
                $avatarData = @unserialize($row->avatar_value, ['allowed_classes' => false]);
                $gender = is_array($avatarData) && isset($avatarData['gender'])
                    ? $avatarData['gender']
                    : 'male';
                    
                if (!isset($groupedNeighbors[$row->uid])) {
                    $groupedNeighbors[$row->uid] = [
                        'uid' => $row->uid,
                        'name' => $row->name,
                        'first_name' => $row->firstName,
                        'last_name' => $row->lastName,
                        'sex' => $gender == 'male' ? 'm' : 'f',
                    ];
                }
            }
            
            $pendingNeighbors = array_values($groupedNeighbors);
        }
        
        return response()->json([
            'pending' => $pendingNeighbors,
            'count' => count($pendingNeighbors)
        ]);
    }

    public function acceptNeighbor(Request $request)
    {
        $validated = $request->validate([
            'neighbor_id' => 'required|string|max:50'
        ]);
        
        $neighborId = $validated['neighbor_id'];
        $user = Auth::user();
        if (!DB::table('users')->where('uid', $neighborId)->exists()) {
            return response()->json(['error' => 'Neighbor not found'], 404);
        }

        DB::transaction(fn () => $this->acceptNeighborPair((string) $user->uid, $neighborId));
        
        return response()->json(['success' => true, 'message' => 'Neighbor request accepted successfully']);
    }

    public function rejectNeighbor(Request $request)
    {
        $user = Auth::user();
        $neighborId = $request->input('neighbor_id');

        $pendingMeta = DB::table('playermeta')
            ->where('uid', $user->uid)
            ->where('meta_key', 'pending_neighbors')
            ->value('meta_value');

        $pendingIds = $pendingMeta ? unserialize($pendingMeta, ['allowed_classes' => false]) : [];
        $pendingIds = array_values(array_filter($pendingIds, fn($id) => $id != $neighborId));

        if (empty($pendingIds)) {
            DB::table('playermeta')
                ->where('uid', $user->uid)
                ->where('meta_key', 'pending_neighbors')
                ->delete();
        } else {
            DB::table('playermeta')
                ->where('uid', $user->uid)
                ->where('meta_key', 'pending_neighbors')
                ->update(['meta_value' => serialize($pendingIds)]);
        }

        return response()->json(['success' => true, 'message' => 'Neighbor request rejected successfully']);
    }

    public function sendNeighborRequest(Request $request)
    {
        $user = Auth::user();
        $neighborId = (string) $request->input('neighbor_id', '');

        $neighborExists = DB::table('users')->where('uid', $neighborId)->exists();
        if (!$neighborExists) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($neighborId === (string) $user->uid) {
            return response()->json(['error' => 'You cannot add yourself as a neighbor'], 422);
        }

        if (self::autoAcceptNeighborRequests($neighborId)) {
            DB::transaction(fn () => $this->acceptNeighborPair($neighborId, (string) $user->uid));

            return response()->json([
                'success' => true,
                'accepted' => true,
                'message' => 'Neighbor request accepted automatically',
            ]);
        }

        $pendingMeta = DB::table('playermeta')
            ->where('uid', $neighborId)
            ->where('meta_key', 'pending_neighbors')
            ->value('meta_value');
        
        $pendingIds = $pendingMeta ? unserialize($pendingMeta, ['allowed_classes' => false]) : [];
        $pendingIds = is_array($pendingIds) ? $pendingIds : [];
        
        if (!in_array($user->uid, $pendingIds)) {
            $pendingIds[] = $user->uid;
            
            $exists = DB::table('playermeta')
                ->where('uid', $neighborId)
                ->where('meta_key', 'pending_neighbors')
                ->exists();
            
            if ($exists) {
                DB::table('playermeta')
                    ->where('uid', $neighborId)
                    ->where('meta_key', 'pending_neighbors')
                    ->update(['meta_value' => serialize($pendingIds)]);
            } else {
                DB::table('playermeta')->insert([
                    'uid' => $neighborId,
                    'meta_key' => 'pending_neighbors',
                    'meta_value' => serialize($pendingIds)
                ]);
            }
        }
        
        return response()->json(['success' => true, 'message' => 'Neighbor request sent successfully']);
    }

    private function acceptNeighborPair(string $receiverId, string $requesterId): void
    {
        $this->removePendingNeighbor($receiverId, $requesterId);
        $this->addCurrentNeighbor($receiverId, $requesterId);
        $this->addCurrentNeighbor($requesterId, $receiverId);
    }

    private function removePendingNeighbor(string $uid, string $neighborId): void
    {
        $pendingMeta = DB::table('playermeta')
            ->where('uid', $uid)
            ->where('meta_key', 'pending_neighbors')
            ->value('meta_value');
        $pendingIds = $pendingMeta ? unserialize($pendingMeta, ['allowed_classes' => false]) : [];
        $pendingIds = is_array($pendingIds)
            ? array_values(array_filter($pendingIds, fn ($id) => (string) $id !== $neighborId))
            : [];

        if (empty($pendingIds)) {
            DB::table('playermeta')->where('uid', $uid)->where('meta_key', 'pending_neighbors')->delete();
        } else {
            DB::table('playermeta')->where('uid', $uid)->where('meta_key', 'pending_neighbors')
                ->update(['meta_value' => serialize($pendingIds)]);
        }
    }

    private function addCurrentNeighbor(string $uid, string $neighborId): void
    {
        $currentMeta = DB::table('playermeta')
            ->where('uid', $uid)
            ->where('meta_key', 'current_neighbors')
            ->value('meta_value');
        $currentIds = $currentMeta ? unserialize($currentMeta, ['allowed_classes' => false]) : [];
        $currentIds = is_array($currentIds) ? $currentIds : [];

        if (!in_array($neighborId, array_map('strval', $currentIds), true)) {
            $currentIds[] = $neighborId;
            DB::table('playermeta')->updateOrInsert(
                ['uid' => $uid, 'meta_key' => 'current_neighbors'],
                ['meta_value' => serialize($currentIds)],
            );
        }
    }
}
