<?php

class PurchaseUnwitherService
{
    /**
     * Complete the client's fallback paid-Unwither transaction in offline
     * mode. Normal play receives a native free consumable, but this path also
     * restores crops without confirming a cash purchase.
     */
    public static function purchaseUnwitherItem($playerObj, $request, $market = null)
    {
        Logger::debug('PurchaseUnwitherService', "Free unwither requested by uid={$playerObj->getUid()}");

        $result = FarmService::useUnwitherConsumable($playerObj, $request, $market);
        $result['data']['unwitherPurchased'] = false;

        return $result;
    }
}
