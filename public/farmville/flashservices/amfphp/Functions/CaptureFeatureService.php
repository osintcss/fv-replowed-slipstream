<?php

require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/capture_feature_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

class CaptureFeatureService
{
    private const GOPHER_FEATURE = 'gophergarden';
    private const SEEN_COOLDOWN_SECONDS = 30000;
    private const TREAT_PRICE = 2;

    public static function onGopherSeen($playerObj, $request, $market = null): array
    {
        return self::resolveGopher($playerObj, $request, false);
    }

    public static function onGopherIgnored($playerObj, $request, $market = null): array
    {
        return self::resolveGopher($playerObj, $request, false);
    }

    public static function onGopherCaptured($playerObj, $request, $market = null): array
    {
        return self::resolveGopher($playerObj, $request, true);
    }

    public static function onPurchaseTreat($playerObj, $request, $market = null): array
    {
        $featureName = $request->params[0] ?? '';
        $uid = $playerObj->getUid();
        $data = self::loadFeature($uid, $featureName);
        if ($data === null) {
            return ['errorType' => 1, 'errorData' => 'Unsupported capture feature'];
        }

        if (UserResources::getCash($uid) < self::TREAT_PRICE || !UserResources::removeCash($uid, self::TREAT_PRICE)) {
            return ['errorType' => 1, 'errorData' => 'Not enough Farm Cash', 'featureData' => $data];
        }

        $data['treatCount']++;
        saveCaptureFeatureData($uid, $featureName, $data);
        return ['featureData' => $data];
    }

    public static function postFeed($playerObj, $request, $market = null): array
    {
        $featureName = $request->params[0] ?? '';
        $data = self::loadFeature($playerObj->getUid(), $featureName);
        if ($data === null) {
            return ['errorType' => 1, 'errorData' => 'Unsupported capture feature'];
        }

        // This installation has no social-feed backend. Returning success
        // lets the client unlock its Ask button instead of leaving it stuck.
        return ['featureData' => $data];
    }

    private static function resolveGopher($playerObj, $request, bool $captured): array
    {
        $featureName = $request->params[0] ?? '';
        $itemName = $request->params[1] ?? '';
        $options = $request->params[2] ?? false;
        $uid = $playerObj->getUid();
        $data = self::loadFeature($uid, $featureName);
        if ($data === null) {
            return ['errorType' => 1, 'errorData' => 'Unsupported capture feature'];
        }

        // A stale queued Flash transaction must not capture a newly selected
        // gopher after the player has refreshed or handled another one.
        if (!is_string($itemName) || $itemName !== $data['nextAvailableGopher']) {
            return ['featureData' => $data, 'stale' => true];
        }

        if ($captured) {
            $paid = $options === true || (is_object($options) && !empty($options->paid)) || (is_array($options) && !empty($options['paid']));
            if (!$paid) {
                // All shipped Gopher Garden characters require one treat.
                // The client makes the same check before sending this call;
                // repeat it here so a forged AMF call cannot go negative.
                if ($data['treatCount'] < 1) {
                    return ['errorType' => 1, 'errorData' => 'Not enough treats', 'featureData' => $data];
                }
                $data['treatCount']--;
            }

            $data['capturedCount'][$itemName] = ((int) ($data['capturedCount'][$itemName] ?? 0)) + 1;
        }

        $data['nextAvailableTime'] = time() + self::SEEN_COOLDOWN_SECONDS;
        $data['nextAvailableGopher'] = chooseNextCaptureFeatureItem($featureName, $itemName);
        saveCaptureFeatureData($uid, $featureName, $data);

        Logger::debug('CaptureFeatureService', sprintf(
            '%s: uid=%s feature=%s item=%s',
            $captured ? 'captured' : 'resolved', $uid, $featureName, $itemName
        ));

        return ['featureData' => $data];
    }

    private static function loadFeature($uid, $featureName): ?array
    {
        if ($featureName !== self::GOPHER_FEATURE) {
            return null;
        }

        return getCaptureFeatureData($uid, $featureName);
    }
}
