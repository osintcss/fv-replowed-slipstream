<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Reads storage definitions from the same AMF asset used by the Flash client.
 *
 * The items table identifies a construction storage class, but its aggregate
 * material total does not describe each part.  Per-part requirements belong to
 * storageConfig.amf.gz, so callers should use this class instead of copying
 * those values into PHP configuration.
 */
final class StorageConfig
{
    /** @var array<string, array<string, int>>|null */
    private static ?array $constructionRequirements = null;

    /**
     * Return the configured construction-part quantities for a storage class.
     *
     * @return array<string, int>
     */
    public static function constructionRequirements(?string $storageClass): array
    {
        $storageClass = trim((string) $storageClass);
        if ($storageClass === '') {
            return [];
        }

        self::loadConstructionRequirements();

        return self::$constructionRequirements[$storageClass] ?? [];
    }

    private static function loadConstructionRequirements(): void
    {
        if (self::$constructionRequirements !== null) {
            return;
        }

        self::$constructionRequirements = [];
        $path = self::storageConfigPath();
        if ($path === null) {
            return;
        }

        try {
            self::loadAmfDeserializer();

            $compressed = file_get_contents($path);
            $raw = $compressed === false
                ? false
                : (function_exists('zlib_decode')
                    ? @zlib_decode($compressed)
                    : @gzuncompress($compressed));
            if (!is_string($raw) || $raw === '') {
                return;
            }

            $decoder = new class extends \Amfphp_Core_Amf_Deserializer {
                public function decodeRaw(string $raw): mixed
                {
                    $this->rawData = $raw;
                    $this->currentByte = 0;
                    $this->resetReferences();

                    return $this->readAmf3Data();
                }
            };

            $config = $decoder->decodeRaw($raw);
            $storage = self::member($config, 'storage');
            $records = self::asList(self::member($storage, 'StorageBuilding'));

            foreach ($records as $record) {
                $storageClass = (string) self::member($record, 'name');
                if ($storageClass === '') {
                    continue;
                }

                $parts = [];
                foreach (self::asList(self::member($record, 'itemName')) as $part) {
                    $name = trim((string) self::member($part, 'value'));
                    $need = (int) self::member($part, 'need');
                    if ($name !== '' && $need > 0) {
                        $parts[$name] = $need;
                    }
                }

                if ($parts !== []) {
                    self::$constructionRequirements[$storageClass] = $parts;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('StorageConfig AMF lookup failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function storageConfigPath(): ?string
    {
        if (defined('AMFPHP_ROOTPATH')) {
            $root = dirname(AMFPHP_ROOTPATH, 2) . '/xml/gz';
        } elseif (function_exists('public_path')) {
            $root = public_path('farmville/xml/gz');
        } else {
            return null;
        }

        $paths = glob($root . '/v*/storageConfig.amf.gz') ?: [];
        if ($paths === []) {
            return null;
        }

        rsort($paths, SORT_NATURAL);

        return $paths[0];
    }

    private static function loadAmfDeserializer(): void
    {
        if (class_exists('Amfphp_Core_Amf_Deserializer')) {
            return;
        }

        if (defined('AMFPHP_ROOTPATH')) {
            $loader = AMFPHP_ROOTPATH . 'ClassLoader.php';
        } elseif (function_exists('public_path')) {
            $loader = public_path('farmville/flashservices/amfphp/ClassLoader.php');
        } else {
            throw new \RuntimeException('AMFPHP loader is unavailable');
        }

        if (!is_file($loader)) {
            throw new \RuntimeException('AMFPHP loader was not found');
        }

        require_once $loader;
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

        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }
}
