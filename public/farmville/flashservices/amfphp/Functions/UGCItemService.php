<?php

declare(strict_types=1);

require_once AMFPHP_ROOTPATH . "Helpers/ugc_helper.php";

/**
 * Server boundary for the generic UGC editor transactions.
 *
 * The decoration dialog saves custom blueprint state while it is open.  The
 * client keeps that state keyed by item code until the later create/purchase
 * transaction assigns a UUID, so it must survive a refresh without becoming
 * a placed item or consuming materials.
 */
class UGCItemService
{
    public static function saveTemporaryUGCState($playerObj, $request, $market = null): array
    {
        $state = self::param($request, 0);
        $packed = ugcPackState($state);
        $item = is_array($packed) && is_string($packed['I'] ?? null)
            ? ugcItemRecordByCode($packed['I'])
            : null;

        if ($packed === null || !ugcIsBlueprint($item)) {
            return self::error('Invalid temporary UGC item state');
        }

        // Temporary editor states are keyed by item code.  UUID-backed
        // states are minted only by the create/purchase transaction.
        $packed['U'] = null;
        if (!ugcSaveState($playerObj->getUid(), $packed)) {
            return self::error('Unable to save temporary UGC item state');
        }

        return ['data' => []];
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

    private static function error(string $message): array
    {
        return [
            'errorType' => 1,
            'errorData' => $message,
            'data' => [],
        ];
    }
}
