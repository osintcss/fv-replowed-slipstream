<?php

require_once AMFPHP_ROOTPATH . 'Helpers/logger.php';
require_once AMFPHP_ROOTPATH . 'Helpers/general_functions.php';
require_once AMFPHP_ROOTPATH . 'Helpers/user_resources.php';

use App\Models\WorldObject;
use App\Support\WorldPersistence;

/**
 * Persists the session state used by FeatureBuilding's AnimalBreedingState.
 * Offspring generation intentionally follows in the finish endpoints; begin
 * records only the verified parents and any love potions the player uses.
 */
class AnimalBreedingService
{
    private const LOG = 'AnimalBreeding';
    private const MAX_ACTIVE_SESSIONS = 3;
    private const LOVE_POTION_ITEM = 'xuk_animal_love_potion';

    public static function onBeginBreeding($playerObj, $request, $market = null): array
    {
        $uid = $playerObj->getUid();
        $session = $request->params[0] ?? null;
        if (!is_object($session)) {
            return self::failure('invalid_session');
        }

        $buildingId = isset($session->buildingId) && is_numeric($session->buildingId)
            ? (int) $session->buildingId : 0;
        $suiteSlot = isset($session->suiteSlot) && is_numeric($session->suiteSlot)
            ? (int) $session->suiteSlot : -1;
        $breedObjects = isset($session->breedObjs) && is_array($session->breedObjs)
            ? $session->breedObjs : [];

        if ($buildingId <= 0 || $suiteSlot < 0 || count($breedObjects) !== 2) {
            return self::failure('invalid_session');
        }

        $worldType = getCurrentWorldType($uid);
        $result = WorldPersistence::transaction(
            $uid,
            $worldType,
            static function (int $worldId) use ($uid, $buildingId, $suiteSlot, $breedObjects, $session): \stdClass {
                $building = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->lockForUpdate()
                    ->first();

                if ($building === null || $building->class_name !== 'FeatureBuilding'
                    || !self::isBreedingHabitat((string) $building->item_name)) {
                    throw new \RuntimeException('invalid_building');
                }

                $hashes = self::validatedBreedHashes($building->contents, $breedObjects);
                if ($hashes === null) {
                    throw new \RuntimeException('invalid_animals');
                }

                $featureName = (string) $building->item_name;
                $config = self::breedingConfig($featureName);

                $components = is_object($building->components) ? $building->components : new \stdClass();
                $parents = self::validatedParents($hashes, $components);
                if ($parents === null) {
                    throw new \RuntimeException('invalid_parent_pair');
                }

                $numPotions = self::requestedPotionCount($session, $config);
                if ($numPotions === null) {
                    throw new \RuntimeException('invalid_potion_count');
                }
                $state = isset($components->extraDataState) && is_object($components->extraDataState)
                    ? $components->extraDataState : new \stdClass();
                $queue = isset($state->breedingQueue) && is_array($state->breedingQueue)
                    ? $state->breedingQueue : [];

                if (count($queue) >= self::MAX_ACTIVE_SESSIONS || isset($queue[$suiteSlot])) {
                    throw new \RuntimeException('breeding_slot_unavailable');
                }
                foreach ($queue as $activeSession) {
                    foreach (($activeSession->breedObjs ?? []) as $activeObject) {
                        if (in_array($activeObject->hash ?? null, $hashes, true)) {
                            throw new \RuntimeException('animal_already_breeding');
                        }
                    }
                }
                if ($numPotions > 0 && !self::consumeLovePotions($uid, $numPotions)) {
                    throw new \RuntimeException('insufficient_love_potions');
                }

                $start = time();
                $savedSession = (object) [
                    'start_ts' => $start,
                    // defaultBreedTimes is indexed by the number of love
                    // potions used (not player level).
                    'finish_ts' => $start + self::breedingDurationSeconds($config, $numPotions),
                    'numPotions' => $numPotions,
                    'buildingId' => $building->object_id,
                    'suiteSlot' => $suiteSlot,
                    'breedObjs' => array_map(static fn (string $hash) => (object) ['hash' => $hash], $hashes),
                    // Keep a server-validated trait snapshot. A stored animal
                    // cannot later be replaced, removed, or rehashed to alter
                    // an already-started breeding outcome.
                    'parentDna' => $parents,
                    'patternGuarantee' => !empty($session->patternGuarantee),
                ];
                $queue[$suiteSlot] = $savedSession;
                ksort($queue);
                $state->breedingQueue = $queue;
                $state->breedHistory = isset($state->breedHistory) && is_object($state->breedHistory)
                    ? $state->breedHistory : new \stdClass();
                $components->extraDataState = $state;
                $building->components = $components;
                $building->save();

                return $state;
            },
        );

        if ($result === false) {
            return self::failure('begin_failed');
        }

        return ['data' => ['extraDataState' => $result]];
    }

    public static function onFinishBreeding($playerObj, $request, $market = null): array
    {
        return self::finishBreeding($playerObj, $request, false);
    }

    public static function onFinishBreedingNow($playerObj, $request, $market = null): array
    {
        return self::finishBreeding($playerObj, $request, true);
    }

    /**
     * The Flash client sends this after a cash level unlock.  Cash is already
     * debited locally by the legacy client; acknowledge the operation so a
     * failed optional share/purchase call cannot block the breeding queue.
     */
    public static function onPurchaseBreedingLevel($playerObj, $request, $market = null): array
    {
        $featureName = is_string($request->params[0] ?? null) ? $request->params[0] : '';
        $targetLevel = is_numeric($request->params[1] ?? null) ? (int) $request->params[1] : 0;
        if (!self::isBreedingHabitat($featureName) || $targetLevel < 1) {
            return self::failure('invalid_level');
        }

        // A cash level purchase does not pass through Flash's local XP
        // handler, so the server must deliver the XML-defined level rewards.
        // The same delivery path is also used by normal breeding completion
        // below; level claims make retries idempotent.
        $skillState = self::updateSkillState($playerObj->getUid(), $featureName, $targetLevel, 0, true);

        return ['data' => [
            'success' => true,
            'featureName' => $featureName,
            'level' => $targetLevel,
            'xp' => $skillState['xp'],
        ]];
    }

    /** Optional social-share hook; no external feed service is configured. */
    public static function getBreedingLevelUpShareLink($playerObj, $request, $market = null): array
    {
        return ['data' => ['rewardLink' => '']];
    }

    /** Optional ask-a-friend hook; keep the transaction successful offline. */
    public static function onAskForBreedingItem($playerObj, $request, $market = null): array
    {
        return ['data' => ['rewardLink' => '']];
    }

    /**
     * Buy a collection-preview animal with the trait envelope selected by the
     * client. The client handles the cash debit; the server supplies the
     * gender-specific mutable animal and its metadata for placement.
     */
    public static function onPurchaseBreedingAnimal($playerObj, $request, $market = null): array
    {
        $featureName = is_string($request->params[0] ?? null) ? $request->params[0] : '';
        $gender = strtoupper((string) ($request->params[1] ?? 'M')) === 'F' ? 'F' : 'M';
        if (!self::isBreedingHabitat($featureName)) {
            return self::failure('invalid_feature');
        }

        $config = self::breedingConfig($featureName);
        $itemName = $gender === 'F' ? $config['femaleItemName'] : $config['maleItemName'];
        $dna = $config['variants'][$itemName] ?? [
            'N' => '', 'G' => $gender,
            'B' => ['H' => ['0', '0'], 'S' => ['8', '8'], 'V' => ['8', '8']],
            'P' => ['T' => [$config['defaultPatternCode']], 'H' => ['0', '0'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        ];
        $dna['G'] = $gender;
        $dna['U'] = (string) $playerObj->getUid();

        return ['data' => [
            'itemName' => $itemName,
            'mutableState' => json_encode($dna),
        ]];
    }

    public static function onSetAnimalName($playerObj, $request, $market = null): array
    {
        $hash = is_string($request->params[1] ?? null) ? $request->params[1] : '';
        $name = is_string($request->params[2] ?? null) ? trim($request->params[2]) : '';
        if ($hash === '' || $name === '' || mb_strlen($name) > 20) {
            return self::failure('invalid_name');
        }

        $uid = $playerObj->getUid();
        $renamed = false;
        $giftbox = getGiftBox($uid);
        foreach ($giftbox as &$entry) {
            foreach (($entry[2] ?? []) as $index => $metadata) {
                $dna = is_string($metadata) ? json_decode($metadata, true) : (is_object($metadata) ? (array) $metadata : null);
                if (!is_array($dna) || self::mutableStateHash($dna) !== $hash) continue;
                $dna['N'] = $name;
                $entry[2][$index] = json_encode($dna);
                $renamed = true;
            }
        }
        unset($entry);
        if ($renamed) saveGiftBox($uid, $giftbox);

        return ['data' => ['success' => $renamed, 'name' => $name]];
    }

    private static function finishBreeding($playerObj, $request, bool $finishNow): array
    {
        $requested = $request->params[0] ?? null;
        $buildingId = is_object($requested) && is_numeric($requested->buildingId ?? null) ? (int) $requested->buildingId : 0;
        $slot = is_object($requested) && is_numeric($requested->suiteSlot ?? null) ? (int) $requested->suiteSlot : -1;
        if ($buildingId <= 0 || $slot < 0) {
            return self::failure('invalid_session');
        }

        $uid = $playerObj->getUid();
        $result = WorldPersistence::transaction($uid, getCurrentWorldType($uid), static function (int $worldId) use ($uid, $buildingId, $slot, $finishNow): array {
            $building = WorldObject::query()->where('world_id', $worldId)->where('object_id', $buildingId)
                ->where('deleted', false)->lockForUpdate()->first();
            if ($building === null) {
                throw new \RuntimeException('invalid_building');
            }
            $featureName = (string) $building->item_name;
            if (!self::isBreedingHabitat($featureName)) {
                throw new \RuntimeException('invalid_building');
            }
            $config = self::breedingConfig($featureName);
            $components = is_object($building->components) ? $building->components : new \stdClass();
            $state = isset($components->extraDataState) && is_object($components->extraDataState) ? $components->extraDataState : new \stdClass();
            $queue = isset($state->breedingQueue) && is_array($state->breedingQueue) ? $state->breedingQueue : [];
            $session = $queue[$slot] ?? null;
            if (!is_object($session)) {
                throw new \RuntimeException('session_not_found');
            }
            if (!$finishNow && time() < (int) ($session->finish_ts ?? 0)) {
                throw new \RuntimeException('breeding_not_complete');
            }
            if ($finishNow && time() < (int) ($session->finish_ts ?? 0)
                && !UserResources::removeCash($uid, (int) $config['cashToFinishNow'])) {
                throw new \RuntimeException('insufficient_cash');
            }

            $outcome = self::breedingOutcome($config, (int) ($session->numPotions ?? 0));
            unset($queue[$slot]);
            $state->breedingQueue = $queue;
            if (!$outcome) {
                $components->extraDataState = $state;
                $building->components = $components;
                $building->save();
                return ['extraDataState' => $state, 'success' => false, 'outcome' => 'failed'];
            }

            $reward = self::animalOffspring($uid, $state, $session, $components, $config);
            $history = isset($state->breedHistory) && is_object($state->breedHistory) ? $state->breedHistory : new \stdClass();
            $history->gender = substr((string) ($history->gender ?? '') . $reward['gender'], -3);
            $state->breedHistory = $history;
            $components->extraDataState = $state;
            $building->components = $components;
            $building->save();
            $skillState = self::updateSkillState(
                $uid,
                $featureName,
                null,
                (int) ($reward['xpAward'] ?? $config['xpBreedSuccess']),
                true
            );
            addGiftByName($uid, $reward['itemName'], 1, $uid, $reward['mutableState']);
            unset($reward['gender']);
            unset($reward['xpAward']);
            return ['extraDataState' => $state, 'success' => true, 'reward' => (object) $reward, 'breedingSkillState' => (object) $skillState];
        });

        return $result === false ? self::failure('finish_failed') : ['data' => $result];
    }

    /** Generate a mutable animal baby using the habitat's parent trait rules. */
    private static function animalOffspring($uid, \stdClass $state, \stdClass $session, \stdClass $components, array $config): array
    {
        $parents = isset($session->parentDna) && is_array($session->parentDna)
            ? $session->parentDna : [];
        // Sessions started before the snapshot field was introduced remain
        // finishable if their exact stored DNA is still available. We never
        // fall back to a guessed variant when it is not.
        if ($parents === []) {
            $hashes = [];
            foreach (($session->breedObjs ?? []) as $breedObject) {
                if (is_object($breedObject) && is_string($breedObject->hash ?? null)) {
                    $hashes[] = $breedObject->hash;
                }
            }
            $parents = self::validatedParents($hashes, $components) ?? [];
        }
        if (count($parents) !== 2) {
            throw new \RuntimeException('parent_dna_missing');
        }
        $parents = array_map(static fn ($parent): array => self::normalizeDna($parent) ?? [], $parents);
        if (!self::isUsableDna($parents[0]) || !self::isUsableDna($parents[1])
            || !in_array('F', array_column($parents, 'G'), true)
            || !in_array('M', array_column($parents, 'G'), true)) {
            throw new \RuntimeException('parent_dna_missing');
        }
        $history = (string) ($state->breedHistory->gender ?? '');
        $gender = mt_rand(0, 9999) < (int) round($config['femaleChance'] * 10000) ? 'F' : 'M';
        if (strlen($history) >= 3 && count(array_unique(str_split(substr($history, -3)))) === 1) {
            $gender = $history[-1] === 'F' ? 'M' : 'F';
        }
        $base = self::childColor($parents[array_rand($parents)]['B'], 240, 15, 15);
        $samePattern = $parents[0]['P']['T'][0] === $parents[1]['P']['T'][0];
        $inherit = !empty($session->patternGuarantee)
            || mt_rand() / mt_getrandmax() <= $config['patternChance'] * ($samePattern ? $config['samePatternMultiplier'] : 1);
        $patternParent = $parents[array_rand($parents)]['P'];
        $pattern = self::childColor($patternParent, 240, 15, 15);
        $maleParent = $parents[0]['G'] === 'M' ? $parents[0] : $parents[1];
        $pattern['T'] = [$inherit ? $maleParent['P']['T'][0] : $config['defaultPatternCode']];
        $dna = [
            'N' => '', 'U' => (string) $uid, 'G' => $gender, 'B' => $base, 'P' => $pattern,
        ];
        // Flash places the feature's default baby (lamb/piglet), then its
        // TransformBuilding flow resolves the DNA gender into the adult.
        // Awarding an adult here leaves the client-created baby without the
        // matching giftbox DNA envelope, so its pattern disappears on reload.
        return ['itemName' => $config['defaultBreedItem'], 'mutableState' => json_encode($dna), 'gender' => $gender];
    }

    /** Return the stored parent DNA exactly, or null when legacy data is incomplete. */
    private static function parentDna(string $hash, \stdClass $components): ?array
    {
        $storageMetadata = isset($components->storageMetadata) && is_object($components->storageMetadata)
            ? $components->storageMetadata : new \stdClass();
        $candidateEntries = [];
        $hasExactMetadataKey = isset($storageMetadata->{$hash})
            && is_array($storageMetadata->{$hash})
            && $storageMetadata->{$hash} !== [];
        if ($hasExactMetadataKey) {
            $candidateEntries = $storageMetadata->{$hash};
        }
        $baseKey = explode(':', $hash, 2)[0] . ':';
        if (isset($storageMetadata->{$baseKey}) && is_array($storageMetadata->{$baseKey})) {
            $candidateEntries = array_merge($candidateEntries, $storageMetadata->{$baseKey});
        }
        $hashSuffix = str_contains($hash, ':') ? (string) explode(':', $hash, 2)[1] : '';
        foreach ($candidateEntries as $metadata) {
            if (is_object($metadata) && isset($metadata->type) && is_string($metadata->type)) {
                $metadata = $metadata->type;
            }
            $decoded = is_string($metadata) ? json_decode($metadata, true) : self::normalizeDna($metadata);
            // The key is the persisted identity used by the feature-slot
            // protocol. Trust an exact keyed record even when its DNA was
            // serialized by an older client with a slightly different hash
            // input; recomputing it here makes a valid pig look unbreedable.
            if (is_array($decoded) && $hashSuffix !== ''
                && ($hasExactMetadataKey || self::mutableStateHash($decoded) === $hashSuffix)) {
                return $decoded;
            }
            if (is_array($decoded) && !str_contains($hash, ':')) {
                return $decoded;
            }
        }
        return null;
    }

    /** Require two distinct stored animals: exactly one ram/boar and one ewe/sow. */
    private static function validatedParents(array $hashes, \stdClass $components): ?array
    {
        $parents = [];
        foreach ($hashes as $hash) {
            $dna = self::parentDna($hash, $components);
            if (!self::isUsableDna($dna)) {
                // We deliberately do not invent traits for a legacy animal.
                // The player can still keep it, but it cannot create a child
                // whose lineage we cannot represent truthfully.
                return null;
            }
            $parents[] = $dna;
        }

        $genders = array_column($parents, 'G');
        sort($genders);
        return $genders === ['F', 'M'] ? $parents : null;
    }

    private static function normalizeDna($dna): ?array
    {
        if (!is_array($dna) && !is_object($dna)) {
            return null;
        }

        $normalized = json_decode(json_encode($dna), true);
        return is_array($normalized) ? $normalized : null;
    }

    private static function isUsableDna($dna): bool
    {
        if (!is_array($dna) || !in_array($dna['G'] ?? null, ['M', 'F'], true)) {
            return false;
        }
        foreach (['B', 'P'] as $trait) {
            if (!is_array($dna[$trait] ?? null)) {
                return false;
            }
            foreach (['H', 'S', 'V'] as $channel) {
                if (!is_array($dna[$trait][$channel] ?? null)
                    || !isset($dna[$trait][$channel][0], $dna[$trait][$channel][1])) {
                    return false;
                }
            }
        }
        return isset($dna['P']['T'][0]) && is_string($dna['P']['T'][0]);
    }

    /** The XML's timing table is indexed by used love-potion count. */
    private static function requestedPotionCount(\stdClass $session, array $config): ?int
    {
        $raw = $session->numPotions ?? 0;
        if (!is_numeric($raw) || (int) $raw != $raw) {
            return null;
        }
        $count = (int) $raw;
        $maximum = max(0, count($config['breedTimes']) - 1);
        return $count >= 0 && $count <= $maximum ? $count : null;
    }

    private static function breedingDurationSeconds(array $config, int $numPotions): int
    {
        $hours = (int) ($config['breedTimes'][$numPotions] ?? $config['breedTimes'][0] ?? 24);
        return max(0, $hours) * 3600;
    }

    private static function breedingOutcome(array $config, int $numPotions): bool
    {
        $chance = (float) $config['baseSuccessChance'] + ($numPotions * (float) $config['lovePotionBonusChance']);
        $chance = max(0.0, min(1.0, $chance));
        return mt_rand(1, 1_000_000) <= (int) round($chance * 1_000_000);
    }

    /** Consume actual potion inventory rather than trusting a client-provided count. */
    private static function consumeLovePotions(int $uid, int $quantity): bool
    {
        $item = getItemByName(self::LOVE_POTION_ITEM, 'db');
        $code = is_array($item) ? ($item['code'] ?? null) : null;
        // Some old generic gifts were saved under their item name because
        // their historical item-code entry is absent from this data set.
        // Accept that existing representation, but still require and remove
        // the actual server-side quantity.
        if (!is_string($code) || $code === '') {
            $giftbox = getGiftBox($uid);
            $code = isset($giftbox[self::LOVE_POTION_ITEM]) ? self::LOVE_POTION_ITEM : null;
        }
        return is_string($code) && $code !== '' && removeGiftByCode($uid, $code, $quantity);
    }

    private static function childColor(array $parent, int $hueRange, int $satRange, int $intRange): array
    {
        $out = [];
        foreach (['H' => [$hueRange, 15], 'S' => [$satRange, 1], 'V' => [$intRange, 1]] as $key => [$range, $variance]) {
            $value = hexdec((string) ($parent[$key][0] ?? '0'));
            $value = ($value + mt_rand(-$variance, $variance) + $range) % $range;
            $out[$key] = [dechex($value), dechex($value)];
        }
        return $out;
    }

    private static function mutableStateHash(array $dna): string
    {
        $state = (string) ($dna['G'] ?? '');
        foreach (['B', 'P'] as $trait) {
            foreach (['H', 'S', 'V'] as $channel) {
                $values = $dna[$trait][$channel] ?? ['', ''];
                $state .= ($values[0] ?? '') . ',' . ($values[1] ?? '');
            }
            if ($trait === 'P') $state .= $dna['P']['T'][0] ?? '';
        }
        return substr(md5($state), 0, 8);
    }

    private static function breedingConfig(string $featureName): array
    {
        static $configs = [];
        if (isset($configs[$featureName])) return $configs[$featureName];
        $path = dirname(AMFPHP_ROOTPATH, 2).'/xml/gz/v855038/AnimalBreeding.xml.gz';
        $xml = simplexml_load_string(zlib_decode((string) file_get_contents($path)));
        $feature = $xml->xpath('/breeding/feature[@name="'.$featureName.'"]')[0] ?? null;
        if ($feature === null) throw new \RuntimeException('breeding_config_missing');
        $values = [];
        foreach ($feature->featureConfig->children() as $node) $values[$node->getName()] = (string) $node;
        $variants = [];
        $variantsByName = [];
        foreach ($feature->variants->variant as $variant) {
            $color = static function ($node): array {
                return ['H' => [dechex((int) explode(',', (string) $node['hue'])[0]), dechex((int) explode(',', (string) $node['hue'])[1])],
                    'S' => [dechex((int) explode(',', (string) $node['saturation'])[0]), dechex((int) explode(',', (string) $node['saturation'])[1])],
                    'V' => [dechex((int) explode(',', (string) $node['intensity'])[0]), dechex((int) explode(',', (string) $node['intensity'])[1])]];
            };
            $base = $color($variant->base); $pattern = $color($variant->pattern); $pattern['T'] = [(string) $variant->pattern['type']];
            $variantData = ['N' => '', 'G' => (string) $variant['gender'], 'B' => $base, 'P' => $pattern];
            $variants[(string) $variant['itemName']] = $variantData;
            $variantsByName[(string) $variant['name']] = $variantData;
        }
        $levelXp = [];
        $levelRewards = [];
        foreach ($feature->levels->level as $level) {
            $levelValue = (int) ($level['value'] ?? 0);
            $levelXp[$levelValue] = (int) ($level['xp'] ?? 0);
            $levelRewards[$levelValue] = [];
            foreach ($level->reward as $reward) {
                $type = trim((string) ($reward['type'] ?? ''));
                $value = trim((string) ($reward['value'] ?? ''));
                if ($type === '' || $value === '') {
                    continue;
                }
                $levelRewards[$levelValue][] = [
                    'type' => $type,
                    'value' => $value,
                    'quantity' => max(1, (int) ($reward['quantity'] ?? 1)),
                ];
            }
        }

        $breedTimes = array_map('intval', array_filter(array_map('trim', explode(',', $values['defaultBreedTimes'] ?? '24')), 'strlen'));
        return $configs[$featureName] = ['femaleChance' => (float) $values['genderFemaleChance'], 'patternChance' => (float) $values['patternChance'],
            'samePatternMultiplier' => (float) $values['samePatternMultiplier'], 'defaultPatternCode' => $values['defaultPatternCode'],
            'maleItemName' => $values['maleItemName'], 'femaleItemName' => $values['femaleItemName'], 'xpBreedSuccess' => (int) ($values['xpBreedSuccess'] ?? 0),
            'defaultBreedItem' => $values['defaultBreedItem'],
            'baseSuccessChance' => (float) ($values['baseSuccessChance'] ?? 1),
            'lovePotionBonusChance' => (float) ($values['lovePotionBonusChance'] ?? 0),
            'cashToFinishNow' => (int) ($values['cashToFinishNow'] ?? 10),
            'breedTimes' => $breedTimes === [] ? [24] : $breedTimes,
            'levelXp' => $levelXp, 'levelRewards' => $levelRewards,
            'variants' => $variants, 'variantsByName' => $variantsByName];
    }

    /** Persist the small skill-state envelope consumed by TInitUser. */
    private static function updateSkillState(
        int $uid,
        string $featureName,
        ?int $level,
        int $xpDelta,
        bool $deliverLevelRewards = false
    ): array
    {
        $raw = get_meta($uid, 'breeding_skill_states');
        $states = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        if (!is_array($states)) $states = [];
        $state = $states[$featureName] ?? [];
        if (is_object($state)) $state = get_object_vars($state);
        if (!is_array($state)) $state = [];
        $state['featureName'] = $featureName;
        $state['level'] = max(1, (int) ($state['level'] ?? 1));
        $state['xp'] = max(0, (int) ($state['xp'] ?? 0));
        $state['milestones'] = is_array($state['milestones'] ?? null) ? $state['milestones'] : [];
        $previousLevel = $state['level'];
        $claimedLevelRewards = is_array($state['levelRewardClaims'] ?? null)
            ? $state['levelRewardClaims'] : [];
        if ($level !== null) {
            $state['level'] = max($state['level'], $level);
            $state['xp'] = 0;
        }
        $state['xp'] += max(0, $xpDelta);
        $config = self::breedingConfig($featureName);
        while ($state['level'] < 30) {
            $nextLevel = $state['level'] + 1;
            $needed = (int) ($config['levelXp'][$nextLevel] ?? 0);
            if ($needed <= 0 || $state['xp'] < $needed) break;
            $state['xp'] -= $needed;
            ++$state['level'];
        }

        if ($deliverLevelRewards && $state['level'] > $previousLevel) {
            $config = self::breedingConfig($featureName);
            for ($rewardLevel = $previousLevel + 1; $rewardLevel <= $state['level']; $rewardLevel++) {
                $claimKey = (string) $rewardLevel;
                if (array_key_exists($claimKey, $claimedLevelRewards)
                    || array_key_exists($rewardLevel, $claimedLevelRewards)) {
                    continue;
                }

                $allDelivered = true;
                foreach (($config['levelRewards'][$rewardLevel] ?? []) as $reward) {
                    if (($reward['type'] ?? '') !== 'item_grant') {
                        continue;
                    }
                    $item = getItemByCode((string) ($reward['value'] ?? ''));
                    if (!is_array($item) || !isset($item['name'])) {
                        $allDelivered = false;
                        Logger::debug(self::LOG, sprintf(
                            'Level reward item missing: uid=%d feature=%s level=%d code=%s',
                            $uid,
                            $featureName,
                            $rewardLevel,
                            (string) ($reward['value'] ?? ''),
                        ));
                        continue;
                    }

                    $quantity = max(1, (int) ($reward['quantity'] ?? 1));
                    $extraData = null;
                    // Big breeding rewards are mutable adult animals.  Their
                    // item code identifies the artwork, but the breeding
                    // parent also needs the exact trait envelope when it is
                    // placed from Giftbox.  Reuse the named XML variant so
                    // a Heart Boar (and the other level animals) survives
                    // placement and reload with its unlocked pattern.
                    $variant = $config['variantsByName'][(string) $item['name']] ?? null;
                    if (is_array($variant)) {
                        $variant['U'] = (string) $uid;
                        $extraData = json_encode($variant);
                    }
                    addGiftByCode($uid, (string) $item['code'], $quantity, $uid, $extraData);
                    Logger::debug(self::LOG, sprintf(
                        'Level reward delivered: uid=%d feature=%s level=%d item=%s code=%s qty=%d',
                        $uid,
                        $featureName,
                        $rewardLevel,
                        (string) $item['name'],
                        (string) $item['code'],
                        $quantity,
                    ));
                }

                if ($allDelivered) {
                    $claimedLevelRewards[$claimKey] = true;
                }
            }
        }
        $state['levelRewardClaims'] = $claimedLevelRewards;
        $states[$featureName] = $state;
        set_meta($uid, 'breeding_skill_states', serialize($states));
        return $state;
    }

    private static function isBreedingHabitat(string $itemName): bool
    {
        return in_array($itemName, ['pigpenv2_finished', 'xuk_sheep_pen_finished'], true);
    }

    private static function validatedBreedHashes($contents, array $breedObjects): ?array
    {
        if (!is_array($contents)) {
            return null;
        }
        $available = [];
        foreach ($contents as $content) {
            $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $count = is_object($content) ? ($content->numItem ?? 0) : ($content['numItem'] ?? 0);
            if (is_string($code) && $code !== '') {
                $available[$code] = ($available[$code] ?? 0) + max(0, (int) $count);
            }
        }

        $hashes = [];
        foreach ($breedObjects as $breedObject) {
            $hash = is_object($breedObject) ? ($breedObject->hash ?? null) : null;
            $code = is_string($hash) ? explode(':', $hash, 2)[0] : '';
            if ($code === '' || ($available[$code] ?? 0) < 1) {
                return null;
            }
            --$available[$code];
            $hashes[] = $hash;
        }

        return count(array_unique($hashes)) === 2 ? $hashes : null;
    }

    private static function failure(string $error): array
    {
        Logger::error(self::LOG, $error);
        return ['data' => ['success' => false, 'error' => $error, 'extraDataState' => new \stdClass()]];
    }
}
