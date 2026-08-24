<?php

require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";

use App\Support\WorldPersistence;

class OrganicFertilizerService
{
    public static function executeOrganicFertilizer($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $currentWorldType = getCurrentWorldType($uid);
        $world = getWorldByType($uid, $currentWorldType);

        if (!empty($world["objectsArray"])) {
            $modified = false;
            $modifiedObjects = [];
            foreach ($world["objectsArray"] as &$obj) {
                if (isset($obj->className) && $obj->className === 'Plot'
                    && isset($obj->state) && $obj->state === 'planted'
                    && isset($obj->itemName)) {
                    $obj->isJumbo = true;
                    $modifiedObjects[] = $obj;
                    $modified = true;
                }
            }
            unset($obj);

            if ($modified) {
                if (!WorldPersistence::updateByPosition($uid, $currentWorldType, $modifiedObjects)) {
                    throw new \Exception("Failed to save world (organic fertilizer) for uid=$uid");
                }
            }
        }

        return array("data" => array());
    }
}
