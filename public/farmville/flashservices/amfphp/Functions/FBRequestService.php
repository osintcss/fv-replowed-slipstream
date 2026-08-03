<?php 

class FBRequestService{
    public static function sendInviteRequest($playerObj, $request){
        foreach($request->params[0] as $uid){
            $playerObj->setPendingNeighbors($uid);
        }

        return [];
    }

    /**
     * Records a completed Ask Items request after the client-side social bridge
     * has returned its request IDs. Facebook no longer accepts these requests,
     * but the Flash client still requires this AMF endpoint to complete its
     * send workflow and dismiss the MFS dialog.
     */
    public static function sendAskItemsRequest($playerObj, $request, $market = null){
        $params = is_array($request->params ?? null) ? $request->params : [];
        $itemName = isset($params[0]) && is_string($params[0]) ? $params[0] : '';
        $featureName = isset($params[1]) && is_string($params[1]) ? $params[1] : '';
        $requestIds = isset($params[2]) && is_array($params[2]) ? $params[2] : [];
        $source = isset($params[3]) && is_string($params[3]) ? $params[3] : '';
        $view = isset($params[5]) && is_string($params[5]) ? $params[5] : '';

        // AMF can represent the IDs as numeric values or strings. Keep only
        // scalar IDs and cap the local audit trail so an untrusted request
        // cannot grow player metadata without bound.
        $requestIds = array_values(array_slice(array_filter($requestIds, function ($id) {
            return is_scalar($id) && (string) $id !== '';
        }), 0, 50));

        $uid = $playerObj->getUid();
        $stored = get_meta($uid, 'sent_ask_item_requests');
        $history = is_string($stored) ? (@unserialize($stored) ?: []) : [];
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'itemName' => $itemName,
            'featureName' => $featureName,
            'requestIds' => $requestIds,
            'source' => $source,
            'view' => $view,
            'sentAt' => time(),
        ];
        set_meta($uid, 'sent_ask_item_requests', serialize(array_slice($history, -100)));

        Logger::debug(
            'FBRequestService',
            sprintf(
                'Offline Ask Items request accepted: uid=%s item=%s feature=%s requests=%d',
                $uid,
                $itemName,
                $featureName,
                count($requestIds)
            )
        );

        return [
            'data' => [
                'success' => true,
                'published' => true,
                'requestIds' => $requestIds,
                'ts' => time(),
            ],
        ];
    }
}
