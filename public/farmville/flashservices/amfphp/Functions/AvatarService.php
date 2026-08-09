<?php

require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

use App\Models\AvatarUnlock;

class AvatarService{
    
    public static function saveAvatar($playerObj, $request){
        $uid = $playerObj->getUid();
        $params = $request->params[0];
        $gender = $request->params[1] ?? "female";

        $avatar = [
            "gender" => $gender,
            "version" => "fv_1",
            "items" => $params
        ];

        $playerObj->setAvatar($avatar);

        self::updateConfigurations($uid, $gender, $params);

        return [];
    }

    
    public static function buyAvatarItem($playerObj, $request){
        $uid = $playerObj->getUid();
        $itemId = (string) ($request->params[0] ?? "");

        if (empty($itemId)) {
            return ["data" => ["success" => false, "error" => "Invalid item"]];
        }

        self::unlockItem($uid, $itemId);

        return ["data" => ["success" => true, "itemId" => $itemId]];
    }

    
    public static function getUnlockedItems($uid){
        return AvatarUnlock::getUnlockedForUser($uid);
    }

    
    public static function isItemUnlocked($uid, $itemId){
        return AvatarUnlock::isUnlocked($uid, $itemId);
    }

    
    public static function unlockItem($uid, $itemId){
        return AvatarUnlock::unlock($uid, $itemId);
    }

    
    public static function getConfigurations($uid){
        $raw = get_meta($uid, 'avatar_configurations');
        if ($raw) {
            $configs = @unserialize($raw);
            if (is_array($configs)) {
                return $configs;
            }
        }
        return [
            "male" => new stdClass(),
            "female" => new stdClass()
        ];
    }

    
    public static function updateConfigurations($uid, $gender, $params){
        $configs = self::getConfigurations($uid);

        if (!isset($configs[$gender]) || !is_array($configs[$gender])) {
            $configs[$gender] = [];
        }

        if (is_object($params) || is_array($params)) {
            foreach ($params as $category => $itemData) {
                if (is_object($itemData) && isset($itemData->itemId)) {
                    $configs[$gender][$category] = (string) $itemData->itemId;
                } elseif (is_array($itemData) && isset($itemData['itemId'])) {
                    $configs[$gender][$category] = (string) $itemData['itemId'];
                }
            }
        }

        set_meta($uid, 'avatar_configurations', serialize($configs));
        return true;
    }
}
