<?php

/**
 * Durable state for legacy capture features.
 *
 * The Flash client treats this state as part of FeatureOptions, but the
 * original service that owned it was never recreated.  Keeping it in its own
 * player-meta record makes it available during init and after each capture
 * transaction without relying on client-side state surviving a refresh.
 */
function getCaptureFeatureData($uid, string $featureName): array
{
    $defaults = getCaptureFeatureDefaults($featureName);
    $raw = get_meta($uid, getCaptureFeatureMetaKey($featureName));
    $saved = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($saved)) {
        saveCaptureFeatureData($uid, $featureName, $defaults);
        return $defaults;
    }

    $data = array_merge($defaults, $saved);
    $data['nextAvailableTime'] = max(0, (int) $data['nextAvailableTime']);
    $data['treatCount'] = max(0, (int) $data['treatCount']);
    $data['optStatus'] = (int) $data['optStatus'] === 0 ? 0 : 1;
    $data['capturedCount'] = is_array($data['capturedCount']) ? $data['capturedCount'] : [];

    if (!in_array($data['nextAvailableGopher'], getCaptureFeatureItems($featureName), true)) {
        $data['nextAvailableGopher'] = $defaults['nextAvailableGopher'];
    }

    // Older partial client state is repaired on read.  This is specifically
    // what prevents CaptureFeatureManager.getCapturedBreakdown from receiving
    // a null capturedCount after a reload.
    if ($data !== $saved) {
        saveCaptureFeatureData($uid, $featureName, $data);
    }

    return $data;
}

function saveCaptureFeatureData($uid, string $featureName, array $data): bool
{
    return set_meta(
        $uid,
        getCaptureFeatureMetaKey($featureName),
        json_encode($data, JSON_UNESCAPED_SLASHES)
    );
}

function getCaptureFeatureDefaults(string $featureName): array
{
    $items = getCaptureFeatureItems($featureName);

    return [
        'nextAvailableTime' => time(),
        'nextAvailableGopher' => $items[0],
        'capturedCount' => [],
        'treatCount' => 1,
        'optStatus' => 1,
    ];
}

function getCaptureFeatureItems(string $featureName): array
{
    // These are the eleven collectible Gopher Garden characters.  Keep this
    // explicit: the database also has gopher_horned_punk, which is an
    // unrelated harvestable animal rather than a capture-feature character.
    if ($featureName !== 'gophergarden') {
        return [];
    }

    return [
        'gopher_daddycarter',
        'gopher_dirtyjackjulep',
        'gopher_dollyhill',
        'gopher_donnydangerous',
        'gopher_mariasicillianno',
        'gopher_moneybags',
        'gopher_nataliagrey',
        'gopher_noname',
        'gopher_rickoverdrive',
        'gopher_studleyduke',
    ];
}

function chooseNextCaptureFeatureItem(string $featureName, ?string $previous = null): string
{
    $items = getCaptureFeatureItems($featureName);
    if (!$items) {
        return '';
    }

    $choices = array_values(array_diff($items, [$previous]));
    return $choices[random_int(0, count($choices) - 1)];
}

function getCaptureFeatureMetaKey(string $featureName): string
{
    return 'capture_feature_' . $featureName;
}
