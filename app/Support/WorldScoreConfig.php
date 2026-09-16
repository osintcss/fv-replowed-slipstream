<?php

namespace App\Support;

/**
 * Resolves expansion score units and their level thresholds from the original
 * FarmVille configuration assets.  The server must calculate a world level
 * from its score; a Flash-reported level is only a presentation detail and can
 * be stale, out of order, or corrupted.
 */
final class WorldScoreConfig
{
    /** @var array<string, string>|null */
    private static ?array $worldToScoreUnit = null;

    /** @var array<string, string>|null */
    private static ?array $scoreAliasToWorld = null;

    /** @var array<string, array<int, array{required: int, level: int}>>|null */
    private static ?array $levelsByScoreUnit = null;

    private function __construct()
    {
    }

    public static function scoreUnitForWorld(string $worldType): ?string
    {
        $worldType = trim($worldType);
        if ($worldType === '' || $worldType === 'farm') {
            return null;
        }

        self::loadWorldMappings();

        return self::$worldToScoreUnit[strtolower($worldType)] ?? ($worldType.'Points');
    }

    public static function worldForScoreUnit(string $worldOrScoreUnit): ?string
    {
        $worldOrScoreUnit = trim($worldOrScoreUnit);
        if ($worldOrScoreUnit === '') {
            return null;
        }

        self::loadWorldMappings();

        $normalized = strtolower($worldOrScoreUnit);
        if (isset(self::$scoreAliasToWorld[$normalized])) {
            return self::$scoreAliasToWorld[$normalized];
        }

        if (str_ends_with($worldOrScoreUnit, 'Points')) {
            return substr($worldOrScoreUnit, 0, -6);
        }

        return $worldOrScoreUnit;
    }

    /**
     * Return all historical metadata suffixes that denote the same score.
     * For example, Emerald Valley uses canonical `oz`, while older saves
     * commonly contain `rainbow` or `rainbowPoints`.
     *
     * @return array<int, string>
     */
    public static function metadataSuffixesForWorld(string $worldType): array
    {
        $worldType = self::worldForScoreUnit($worldType) ?? trim($worldType);
        $scoreUnit = self::scoreUnitForWorld($worldType);
        if ($worldType === '' || $worldType === 'farm' || $scoreUnit === null) {
            return [];
        }

        $suffixes = [$worldType, $scoreUnit];
        if (str_ends_with($scoreUnit, 'Points')) {
            $suffixes[] = substr($scoreUnit, 0, -6);
        }

        return array_values(array_unique(array_filter(
            array_map('trim', $suffixes),
            static fn (string $suffix): bool => $suffix !== '',
        )));
    }

    /**
     * Return the configured level for a score, or null if the score unit is
     * not represented by the recovered original world-score asset.
     */
    public static function levelForScore(string $scoreUnit, int $score): ?int
    {
        self::loadScoreLevels();

        $levels = self::$levelsByScoreUnit[$scoreUnit] ?? null;
        if ($levels === null) {
            return null;
        }

        $score = max(0, $score);
        $level = 1;
        foreach ($levels as $entry) {
            if ($score < $entry['required']) {
                break;
            }

            $level = max(1, $entry['level']);
        }

        return $level;
    }

    private static function loadWorldMappings(): void
    {
        if (self::$worldToScoreUnit !== null && self::$scoreAliasToWorld !== null) {
            return;
        }

        self::$worldToScoreUnit = [];
        self::$scoreAliasToWorld = [];

        $path = self::publicPath('props/gameSettings.json');
        $contents = $path !== null ? @file_get_contents($path) : false;
        $settings = is_string($contents) ? json_decode($contents, true) : null;
        $expansions = $settings['settings']['expansions']['expansion'] ?? [];
        $expansions = is_array($expansions) ? $expansions : [];

        foreach ($expansions as $expansion) {
            if (!is_array($expansion)) {
                continue;
            }

            $worldType = trim((string) ($expansion['@name'] ?? ''));
            $scoreUnit = trim((string) ($expansion['@score'] ?? ''));
            if ($worldType === '' || $scoreUnit === '') {
                continue;
            }

            $worldKey = strtolower($worldType);
            self::$worldToScoreUnit[$worldKey] = $scoreUnit;
            self::$scoreAliasToWorld[$worldKey] = $worldType;
            self::$scoreAliasToWorld[strtolower($scoreUnit)] = $worldType;

            if (str_ends_with($scoreUnit, 'Points')) {
                self::$scoreAliasToWorld[strtolower(substr($scoreUnit, 0, -6))] = $worldType;
            }
        }
    }

    private static function loadScoreLevels(): void
    {
        if (self::$levelsByScoreUnit !== null) {
            return;
        }

        self::$levelsByScoreUnit = [];
        $path = self::publicPath('farmville/xml/gz/v855038/worldScore.amf.gz');
        if ($path === null) {
            return;
        }

        try {
            $compressed = @file_get_contents($path);
            $raw = is_string($compressed)
                ? (function_exists('zlib_decode') ? @zlib_decode($compressed) : @gzuncompress($compressed))
                : false;
            if (!is_string($raw) || $raw === '') {
                return;
            }

            self::loadAmfDeserializer();
            $decoder = new class extends \Amfphp_Core_Amf_Deserializer {
                public function decodeRaw(string $raw): mixed
                {
                    $this->rawData = $raw;
                    $this->currentByte = 0;
                    $this->resetReferences();

                    return $this->readAmf3Data();
                }
            };

            $root = $decoder->decodeRaw($raw);
            $records = is_object($root) ? get_object_vars($root) : (is_array($root) ? $root : []);
            foreach ($records as $record) {
                // worldScore.amf.gz is keyed as worldScoreOz, worldScoreSpook,
                // etc. Each wrapper contains scores.score, which carries the
                // actual id and level thresholds.
                $record = self::member(self::member($record, 'scores'), 'score');
                $scoreUnit = trim((string) self::member($record, 'id'));
                if ($scoreUnit === '') {
                    continue;
                }

                $levels = [];
                foreach (self::asList(self::member($record, 'level')) as $entry) {
                    $required = self::member($entry, 'required');
                    $level = self::member($entry, 'num');
                    if (!is_numeric($required) || !is_numeric($level)) {
                        continue;
                    }

                    $levels[] = [
                        'required' => max(0, (int) $required),
                        'level' => max(0, (int) $level),
                    ];
                }

                if ($levels !== []) {
                    usort($levels, static fn (array $left, array $right): int => $left['required'] <=> $right['required']);
                    self::$levelsByScoreUnit[$scoreUnit] = $levels;
                }
            }
        } catch (\Throwable) {
            // An unknown or unavailable asset must not prevent game startup.
            // Callers fall back to the previously persisted level in that case.
        }
    }

    private static function loadAmfDeserializer(): void
    {
        if (class_exists('Amfphp_Core_Amf_Deserializer', false)) {
            return;
        }

        $root = defined('AMFPHP_ROOTPATH')
            ? AMFPHP_ROOTPATH
            : self::publicPath('farmville/flashservices/amfphp/');
        if (!is_string($root) || $root === '') {
            throw new \RuntimeException('AMFPHP root is unavailable.');
        }

        require_once $root.'Core/Common/IDeserializer.php';
        require_once $root.'Core/Amf/Constants.php';
        require_once $root.'Core/Amf/Types/ByteArray.php';
        require_once $root.'Core/Amf/Types/Undefined.php';
        require_once $root.'Core/Amf/Types/Date.php';
        require_once $root.'Core/Amf/Types/Vector.php';
        require_once $root.'Core/Amf/Types/Xml.php';
        require_once $root.'Core/Amf/Types/XmlDocument.php';
        require_once $root.'Core/Exception.php';
        require_once $root.'Core/Amf/Deserializer.php';
    }

    private static function publicPath(string $relativePath): ?string
    {
        if (function_exists('public_path')) {
            return public_path($relativePath);
        }

        return null;
    }

    private static function member(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        return is_object($value) ? ($value->{$key} ?? null) : null;
    }

    /** @return array<int, mixed> */
    private static function asList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return [$value];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
