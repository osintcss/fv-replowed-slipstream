<?php

require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";

use App\Models\Item;

/**
 * Small server-side boundary for the UGC decoration data used by the Flash
 * client.  The legacy client stores feature options and UGC item states as
 * separate payloads, so keep those shapes intact even though both values are
 * backed by playermeta in this installation.
 */
const UGC_FEATURE_NAME = 'UGCDeco';
const UGC_FEATURE_OPTION = 'UGCDeco';
const UGC_FEATURE_META_KEY = 'ugc_deco_feature_data';
const UGC_ITEM_META_KEY = 'ugc_item_data';
const UGC_DEFAULT_MATERIAL_CAP = 100;

if (!function_exists('ugcNormalizeValue')) {
    /** Convert AMF stdClass values into recursively normalised PHP arrays. */
    function ugcNormalizeValue($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = ugcNormalizeValue($child);
            }
            return $result;
        }

        return $value;
    }
}

if (!function_exists('ugcReadValue')) {
    function ugcReadValue($value, string $key, $default = null)
    {
        if (is_array($value) && array_key_exists($key, $value)) {
            return $value[$key];
        }

        if (is_object($value) && property_exists($value, $key)) {
            return $value->{$key};
        }

        return $default;
    }
}

if (!function_exists('ugcLoadMetaArray')) {
    function ugcLoadMetaArray(int|string $uid, string $key): array
    {
        $raw = get_meta($uid, $key);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $value = @unserialize($raw);
        if ($value === false && $raw !== 'b:0;') {
            $value = json_decode($raw, true);
        }

        $value = ugcNormalizeValue($value);
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('ugcSaveMetaArray')) {
    function ugcSaveMetaArray(int|string $uid, string $key, array $value): bool
    {
        return set_meta($uid, $key, serialize($value)) === true;
    }
}

if (!function_exists('ugcDefaultFeatureData')) {
    function ugcDefaultFeatureData(): array
    {
        return [
            'ugc_materials' => [],
            'ugc_completed' => [],
        ];
    }
}

if (!function_exists('ugcNormalizeFeatureData')) {
    function ugcNormalizeFeatureData($value): array
    {
        $value = ugcNormalizeValue($value);
        $data = is_array($value) ? $value : [];
        $defaults = ugcDefaultFeatureData();

        $materials = ugcNormalizeValue($data['ugc_materials'] ?? []);
        $normalizedMaterials = [];
        if (is_array($materials)) {
            foreach ($materials as $name => $count) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $normalizedMaterials[$name] = max(0, (int) $count);
            }
        }

        $completed = ugcNormalizeValue($data['ugc_completed'] ?? []);
        $normalizedCompleted = [];
        if (is_array($completed)) {
            foreach ($completed as $name => $state) {
                // Older Flash saves can represent this as a list of names;
                // the shipped client checks hasOwnProperty(name), so expose
                // both old and new forms as an associative object.
                if (is_int($name) && is_string($state) && trim($state) !== '') {
                    $normalizedCompleted[$state] = true;
                    continue;
                }
                if (is_string($name) && trim($name) !== '') {
                    $normalizedCompleted[$name] = (bool) $state;
                }
            }
        }

        $lastEdited = ugcNormalizeValue($data['ugc_lastEdited'] ?? []);
        if (is_string($lastEdited) && $lastEdited !== '') {
            $lastEdited = [UGC_FEATURE_NAME => $lastEdited];
        }
        if (!is_array($lastEdited)) {
            $lastEdited = [];
        }

        return array_merge($defaults, [
            'ugc_materials' => $normalizedMaterials,
            'ugc_completed' => $normalizedCompleted,
            'ugc_lastEdited' => $lastEdited,
        ]);
    }
}

if (!function_exists('ugcLoadFeatureData')) {
    function ugcLoadFeatureData(int|string $uid): array
    {
        return ugcNormalizeFeatureData(ugcLoadMetaArray($uid, UGC_FEATURE_META_KEY));
    }
}

if (!function_exists('ugcSaveFeatureData')) {
    function ugcSaveFeatureData(int|string $uid, array $data): bool
    {
        return ugcSaveMetaArray($uid, UGC_FEATURE_META_KEY, ugcNormalizeFeatureData($data));
    }
}

if (!function_exists('ugcLoadItemData')) {
    function ugcLoadItemData(int|string $uid): array
    {
        $data = ugcLoadMetaArray($uid, UGC_ITEM_META_KEY);
        $result = [];

        foreach ($data as $key => $state) {
            $state = ugcNormalizeValue($state);
            if (!is_array($state)) {
                continue;
            }

            $uuid = $state['U'] ?? null;
            $itemCode = $state['I'] ?? null;
            $stateKey = is_string($uuid) && $uuid !== ''
                ? $uuid
                : (is_string($itemCode) ? $itemCode : (string) $key);
            if ($stateKey !== '') {
                $result[$stateKey] = $state;
            }
        }

        return $result;
    }
}

if (!function_exists('ugcSaveItemData')) {
    function ugcSaveItemData(int|string $uid, array $data): bool
    {
        return ugcSaveMetaArray($uid, UGC_ITEM_META_KEY, ugcLoadItemDataFromArray($data));
    }
}

if (!function_exists('ugcLoadItemDataFromArray')) {
    function ugcLoadItemDataFromArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $state) {
            $state = ugcNormalizeValue($state);
            if (!is_array($state)) {
                continue;
            }

            $uuid = $state['U'] ?? null;
            $itemCode = $state['I'] ?? null;
            $stateKey = is_string($uuid) && $uuid !== ''
                ? $uuid
                : (is_string($itemCode) ? $itemCode : (string) $key);
            if ($stateKey !== '') {
                $result[$stateKey] = $state;
            }
        }
        return $result;
    }
}

if (!function_exists('ugcPackState')) {
    function ugcPackState($state, ?string $uuid = null): ?array
    {
        $state = ugcNormalizeValue($state);
        if (!is_array($state)) {
            return null;
        }

        $itemCode = $state['I'] ?? null;
        if (!is_string($itemCode) || trim($itemCode) === '') {
            return null;
        }

        $packed = [
            'U' => $uuid ?? ($state['U'] ?? null),
            'I' => $itemCode,
        ];

        $name = $state['N'] ?? null;
        if (is_string($name) && $name !== '') {
            $packed['N'] = mb_substr($name, 0, 32);
        }

        $layers = ugcNormalizeValue($state['D'] ?? []);
        if (is_array($layers) && $layers !== []) {
            $packedLayers = [];
            foreach ($layers as $layerKey => $layerState) {
                $layerState = ugcNormalizeValue($layerState);
                if (!is_array($layerState) || !is_string($layerState['I'] ?? null)) {
                    continue;
                }
                $packedLayer = ['I' => $layerState['I']];
                if (array_key_exists('C', $layerState) && is_scalar($layerState['C'])) {
                    $packedLayer['C'] = (string) $layerState['C'];
                }
                $packedLayers[(string) $layerKey] = $packedLayer;
            }
            if ($packedLayers !== []) {
                $packed['D'] = $packedLayers;
            }
        }

        return $packed;
    }
}

if (!function_exists('ugcSaveState')) {
    function ugcSaveState(int|string $uid, array $state): bool
    {
        $packed = ugcPackState($state);
        if ($packed === null) {
            return false;
        }

        $data = ugcLoadItemData($uid);
        $key = is_string($packed['U'] ?? null) && $packed['U'] !== ''
            ? $packed['U']
            : $packed['I'];
        $data[$key] = $packed;
        return ugcSaveMetaArray($uid, UGC_ITEM_META_KEY, ugcLoadItemDataFromArray($data));
    }
}

if (!function_exists('ugcGetStateByUuid')) {
    function ugcGetStateByUuid(int|string $uid, string $uuid): ?array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }

        $data = ugcLoadItemData($uid);
        $state = $data[$uuid] ?? null;
        return is_array($state) ? $state : null;
    }
}

if (!function_exists('ugcFeatureOptionsForClient')) {
    function ugcFeatureOptionsForClient(int|string $uid): array
    {
        return [
            UGC_FEATURE_NAME => [
                UGC_FEATURE_OPTION => ugcLoadFeatureData($uid),
            ],
        ];
    }
}

if (!function_exists('ugcItemRecordByName')) {
    function ugcItemRecordByName(string $name): ?array
    {
        $record = Item::query()->where('name', $name)->first();
        if (!$record) {
            return null;
        }

        $data = $record->itemData;
        $data = is_object($data) ? get_object_vars($data) : $data;
        return is_array($data) ? array_merge(['name' => $record->name, 'code' => $record->code], $data) : null;
    }
}

if (!function_exists('ugcItemRecordByCode')) {
    function ugcItemRecordByCode(string $code): ?array
    {
        // The imported catalog contains many legacy items whose codes differ
        // only by case. MySQL's usual case-insensitive comparison can return
        // an unrelated item before the UGC blueprint (for example 7DV also
        // exists as 7dv/7Dv). Prefer the exact UGC record, then an exact
        // code match, so UGCDecoration requests cannot resolve to a random
        // legacy item.
        $records = Item::query()->where('code', $code)->get();
        $record = $records->first(static function ($candidate) use ($code): bool {
            $data = $candidate->itemData;
            return (string) $candidate->getRawOriginal('code') === $code
                && is_array($data)
                && ($data['className'] ?? null) === 'UGCDecoration';
        });
        $record ??= $records->first(static function ($candidate): bool {
            $data = $candidate->itemData;
            return is_array($data) && ($data['className'] ?? null) === 'UGCDecoration';
        });
        $record ??= $records->first(static function ($candidate) use ($code): bool {
            return (string) $candidate->getRawOriginal('code') === $code;
        });
        if (!$record) {
            return null;
        }

        $data = $record->itemData;
        $data = is_object($data) ? get_object_vars($data) : $data;
        return is_array($data) ? array_merge(['name' => $record->name, 'code' => $record->code], $data) : null;
    }
}

if (!function_exists('ugcIsBlueprint')) {
    function ugcIsBlueprint(?array $item): bool
    {
        if (!is_array($item)) {
            return false;
        }

        return ($item['className'] ?? null) === 'UGCDecoration'
            && is_string($item['name'] ?? null)
            && $item['name'] !== '';
    }
}

if (!function_exists('ugcIsMaterial')) {
    function ugcIsMaterial(?array $item): bool
    {
        return is_array($item) && ($item['className'] ?? null) === 'ACUGCDecorationMaterial';
    }
}

if (!function_exists('ugcMaterialPrice')) {
    function ugcMaterialPrice(?array $item): array
    {
        if (!is_array($item)) {
            return [];
        }

        $rawPrice = ugcNormalizeValue($item['materialPrice'] ?? []);
        if (!is_array($rawPrice)) {
            return [];
        }

        // The imported item catalog stores the production shape as
        // {"material":[{"name":"...","quantity":"9"}]}. Keep accepting
        // the compact name-to-count map used by older fixtures as well.
        if (array_key_exists('material', $rawPrice)) {
            $rawPrice = ugcNormalizeValue($rawPrice['material']);
        }
        if (!is_array($rawPrice)) {
            return [];
        }

        $price = [];
        foreach ($rawPrice as $name => $amount) {
            if (is_int($name) && is_array($amount)) {
                $name = $amount['name'] ?? $amount['item'] ?? null;
                $amount = $amount['amount'] ?? $amount['count'] ?? $amount['quantity'] ?? 0;
            }
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $amount = (int) $amount;
            if ($amount > 0) {
                $price[$name] = ($price[$name] ?? 0) + $amount;
            }
        }

        return $price;
    }
}

if (!function_exists('ugcNewUuidV4')) {
    function ugcNewUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
