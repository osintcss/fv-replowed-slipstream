<?php

require_once AMFPHP_ROOTPATH . "Helpers/ugc_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

use Illuminate\Support\Facades\DB;

/**
 * Server contract for the predefined UGC decoration/building flow.
 *
 * Custom layer editing has deliberately been left for a later milestone. A
 * plain blueprint state contains only its base item code, which is enough for
 * the Spooky Reality houses shown in the client dialog.
 */
class UGCDecoService
{
    public static function setLastEditedBlueprint($playerObj, $request, $market = null): array
    {
        $uid = $playerObj->getUid();
        $featureName = self::param($request, 0);
        $itemName = self::param($request, 1);
        $item = is_string($itemName) ? ugcItemRecordByName($itemName) : null;

        if (!is_string($featureName) || trim($featureName) === '' || !ugcIsBlueprint($item)) {
            return self::error('Invalid UGC blueprint selection');
        }

        $itemFeatureName = (string) ($item['gameSettingsFeatureName'] ?? '');
        if ($itemFeatureName !== '' && $itemFeatureName !== $featureName) {
            return self::error('UGC blueprint does not belong to this feature');
        }

        $data = ugcLoadFeatureData($uid);
        $data['ugc_lastEdited'][$featureName] = $itemName;
        if (!ugcSaveFeatureData($uid, $data)) {
            return self::error('Unable to save UGC blueprint selection');
        }

        return self::success($uid, $data);
    }

    public static function purchasePart($playerObj, $request, $market = null): array
    {
        $uid = $playerObj->getUid();
        $itemName = self::param($request, 0);
        $quantity = (int) self::param($request, 1, 1);
        $item = is_string($itemName) ? ugcItemRecordByName($itemName) : null;

        if (!ugcIsMaterial($item) || $quantity !== 1) {
            return self::error('Invalid UGC material purchase');
        }

        $cashCost = max(0, (int) ($item['cash'] ?? 0)) * $quantity;
        try {
            $result = DB::transaction(function () use ($uid, $itemName, $quantity, $cashCost): bool {
                $data = ugcLoadFeatureData($uid);
                $current = (int) ($data['ugc_materials'][$itemName] ?? 0);
                if ($current + $quantity > UGC_DEFAULT_MATERIAL_CAP) {
                    return false;
                }

                if ($cashCost > 0 && !UserResources::removeCash($uid, $cashCost)) {
                    return false;
                }

                $data['ugc_materials'][$itemName] = $current + $quantity;
                if (!ugcSaveFeatureData($uid, $data)) {
                    throw new \RuntimeException('Unable to save UGC material data');
                }
                return true;
            });
        } catch (\Throwable $exception) {
            Logger::error('UGCDecoService', 'purchasePart failed: '.$exception->getMessage());
            $result = false;
        }

        if ($result !== true) {
            return self::error('Unable to purchase UGC material');
        }

        return self::success($uid, ugcLoadFeatureData($uid));
    }

    public static function purchaseUGCDecoration($playerObj, $request, $market = null): array
    {
        $uid = $playerObj->getUid();
        $clientState = self::param($request, 0);
        $instanceId = (int) self::param($request, 1, 0);
        $packedState = ugcPackState($clientState);
        $item = null;
        if (is_array($packedState)) {
            // Newer clients include the blueprint name alongside its code.
            // Resolve by name first because the legacy catalog has
            // case-colliding codes; retain the code lookup for older clients.
            $itemName = $packedState['N'] ?? null;
            if (is_string($itemName) && $itemName !== '') {
                $item = ugcItemRecordByName($itemName);
            }
            if (!ugcIsBlueprint($item)) {
                $item = ugcItemRecordByCode($packedState['I']);
            }
        }

        if ($instanceId !== 0) {
            return self::error('Editing existing UGC buildings is not implemented yet');
        }

        if (!ugcIsBlueprint($item) || $packedState === null) {
            return self::error('Invalid UGC decoration state');
        }

        // The first milestone intentionally supports the plain predefined
        // blueprint state only. Custom layers are a separate server contract.
        if (isset($packedState['D']) && $packedState['D'] !== []) {
            return self::error('Custom UGC layers are not implemented yet');
        }

        // The Flash placement flow uses the authoritative catalog name/code
        // after the purchase callback returns.  Older clients only send the
        // code, so fill both fields from the resolved UGC blueprint before
        // persisting the state.
        $packedState['I'] = (string) ($item['code'] ?? $packedState['I']);
        $packedState['N'] = mb_substr((string) ($item['name'] ?? ''), 0, 32);

        $requirements = ugcMaterialPrice($item);
        $xpGain = max(0, (int) ($item['ugcXp'] ?? $item['xpGain'] ?? 0));
        $featureData = null;
        $savedState = null;
        try {
            $success = DB::transaction(function () use ($uid, $packedState, $item, $requirements, $xpGain, &$featureData, &$savedState): bool {
                $featureData = ugcLoadFeatureData($uid);
                foreach ($requirements as $materialName => $required) {
                    $available = (int) ($featureData['ugc_materials'][$materialName] ?? 0);
                    if ($available < $required) {
                        return false;
                    }
                }

                foreach ($requirements as $materialName => $required) {
                    $remaining = (int) ($featureData['ugc_materials'][$materialName] ?? 0) - $required;
                    $featureData['ugc_materials'][$materialName] = max(0, $remaining);
                }

                $featureData['ugc_completed'][$item['name'] ?? $packedState['I']] = true;
                $uuid = ugcNewUuidV4();
                $savedState = ugcPackState($packedState, $uuid);
                if ($savedState === null) {
                    throw new \RuntimeException('Unable to pack UGC item state');
                }

                $itemData = ugcLoadItemData($uid);
                $itemData[$uuid] = $savedState;

                if (!ugcSaveFeatureData($uid, $featureData)
                    || !ugcSaveMetaArray($uid, UGC_ITEM_META_KEY, ugcLoadItemDataFromArray($itemData))) {
                    throw new \RuntimeException('Unable to save UGC item data');
                }

                // TCreateUGCDecoration immediately enters the normal
                // Giftbox placement mode after this callback.  Keep the
                // newly minted UUID as raw extraData: TUGCItemPlace compares
                // that value directly with ugcItemState.U when selecting the
                // source item.  The placement request consumes this exact
                // entry after the world row is created.
                addGiftByCode($uid, (string) $item['code'], 1, null, $uuid);

                if ($xpGain > 0 && !UserResources::addXp($uid, $xpGain)) {
                    throw new \RuntimeException('Unable to award UGC experience');
                }

                return true;
            });
        } catch (\Throwable $exception) {
            Logger::error('UGCDecoService', 'purchaseUGCDecoration failed: '.$exception->getMessage());
            $success = false;
        }

        if ($success !== true || !is_array($savedState) || !is_array($featureData)) {
            return self::error('Not enough UGC materials');
        }

        return [
            // TCreateUGCDecoration reads these fields directly from the
            // transaction result.  Nesting them under `data` makes the
            // server-side purchase succeed while leaving the Flash client
            // stuck on "Creating" with no item to place.
            'ugcItemState' => $savedState,
            'xpGain' => $xpGain,
            'featureData' => $featureData,
            'storageData' => [
                GIFTBOX_STORAGE_KEY => buildGiftBoxStorageData($uid),
            ],
            'metadata' => [
                'FeatureOptions' => [
                    UGC_FEATURE_NAME => [
                        UGC_FEATURE_OPTION => $featureData,
                    ],
                ],
            ],
        ];
    }

    private static function param($request, int $index, $default = null)
    {
        $params = is_object($request) ? ($request->params ?? []) : [];
        if (is_array($params) && array_key_exists($index, $params)) {
            return $params[$index];
        }
        if (is_object($params) && property_exists($params, (string) $index)) {
            return $params->{(string) $index};
        }
        return $default;
    }

    private static function success(int|string $uid, array $featureData): array
    {
        return [
            'data' => [],
            'metadata' => [
                'FeatureOptions' => [
                    UGC_FEATURE_NAME => [
                        UGC_FEATURE_OPTION => $featureData,
                    ],
                ],
            ],
        ];
    }

    private static function error(string $message): array
    {
        return [
            'errorType' => 1,
            'errorData' => $message,
            'data' => [],
        ];
    }
}
