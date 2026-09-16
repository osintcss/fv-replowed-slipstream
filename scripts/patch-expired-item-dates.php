<?php

declare(strict_types=1);

/**
 * Keep legacy and optimized item catalogs from hiding historical content.
 *
 * The Flash client rejects an item when limitedEnd is before the server clock.
 * The catalog is shipped as XML and AMF3, so both forms are rewritten during
 * the image build. AMF3 is streamed so the 42 MB payload is never decoded into
 * a giant PHP object graph.
 */

$basePath = getenv('FARMVILLE_ITEM_CATALOG_PATH') ?: (__DIR__ . '/../public/farmville/xml/gz/v855038');
$targetYear = '2099';
$cutoff = new DateTimeImmutable('now');
$xmlFiles = [
    ['path' => $basePath . '/items.xml', 'compressed' => false],
    ['path' => $basePath . '/items1.xml', 'compressed' => false],
    ['path' => $basePath . '/items.xml.gz', 'compressed' => true],
    ['path' => $basePath . '/items.xml.gz1', 'compressed' => true],
];

$parseDate = static function (string $value): ?DateTimeImmutable {
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($value), $matches)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!n/j/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}");
    $errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        ? $date
        : null;
};

$patchXml = static function (string $xml) use ($cutoff, $parseDate): array {
    $count = 0;
    $patched = preg_replace_callback(
        '/(<limitedEnd>)([^<]+)(<\/limitedEnd>)/',
        static function (array $matches) use ($cutoff, $parseDate, &$count): string {
            $date = $parseDate($matches[2]);
            if ($date === null || $date >= $cutoff) {
                return $matches[0];
            }

            $count++;

            return $matches[1] . '12/31/2099' . $matches[3];
        },
        $xml,
    );

    if ($patched === null) {
        throw new RuntimeException('Could not patch XML item expiration dates.');
    }

    return [$patched, $count];
};

/**
 * Rewrites AMF3 while maintaining a separate output string-reference table.
 * This is important because limitedStart and limitedEnd often share an AMF
 * string reference; changing that shared entry would move limitedStart to
 * 2099 as well and hide the item until then.
 */
final class ExpiredItemAmfRewriter
{
    private int $offset = 0;

    private string $output = '';

    /** @var array<int, int> */
    private array $stringOffsets = [];

    /** @var array<int, int> */
    private array $stringLengths = [];

    /** @var array<int, int> */
    private array $outputStringIndexes = [];

    /** @var array<int, bool> */
    private array $patchedStrings = [];

    private int $outputStringCount = 0;

    private int $patchedCount = 0;

    /** @var array<int, array{members: array<int, string>, dynamic: bool, externalizable: bool}> */
    private array $definitions = [];

    public function __construct(
        private readonly string $raw,
        private readonly DateTimeImmutable $cutoff,
        private readonly string $targetYear,
    ) {
    }

    public function rewrite(): array
    {
        $this->readValue(null);

        if ($this->offset !== strlen($this->raw)) {
            throw new RuntimeException(sprintf(
                'Optimized item AMF has %d unread bytes.',
                strlen($this->raw) - $this->offset,
            ));
        }

        return [$this->output, $this->patchedCount];
    }

    private function readValue(?string $fieldName): void
    {
        $marker = $this->readByte();
        $this->output .= chr($marker);

        switch ($marker) {
            case 0x00: // undefined
            case 0x01: // null
            case 0x02: // false
            case 0x03: // true
                return;
            case 0x04: // integer
                $this->appendU29($this->readU29());
                return;
            case 0x05: // number
                $this->appendBytes(8);
                return;
            case 0x06: // string
                $this->readString($fieldName);
                return;
            case 0x07: // XML document
            case 0x0B: // XML
                $this->readByteArrayLike();
                return;
            case 0x08: // date
                $this->readReferenceOrInlineObject(8);
                return;
            case 0x09: // array
                $this->readArray();
                return;
            case 0x0A: // object
                $this->readObject();
                return;
            case 0x0C: // byte array
                $this->readByteArrayLike();
                return;
            case 0x0D: // vector<int>
            case 0x0E: // vector<uint>
            case 0x0F: // vector<double>
            case 0x10: // vector<object>
                $this->readVector($marker);
                return;
            case 0x11: // dictionary
                $this->readDictionary();
                return;
            default:
                throw new RuntimeException(sprintf(
                    'Unknown AMF3 marker 0x%02X at offset %d.',
                    $marker,
                    $this->offset - 1,
                ));
        }
    }

    private function readArray(): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 0) {
            return;
        }

        $count = $handle >> 1;
        while (($key = $this->readString()) !== '') {
            $this->readValue($key);
        }

        for ($i = 0; $i < $count; $i++) {
            $this->readValue(null);
        }
    }

    private function readObject(): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 0) {
            return;
        }

        $traitInfo = $handle >> 1;
        if (($traitInfo & 1) === 1) {
            $this->readString(); // type identifier
            $traitFlags = $traitInfo >> 1;
            $externalizable = ($traitFlags & 1) === 1;
            $dynamic = (($traitFlags >> 1) & 1) === 1;
            $memberCount = $traitFlags >> 2;
            $members = [];
            for ($i = 0; $i < $memberCount; $i++) {
                $members[] = $this->readString();
            }

            $this->definitions[] = [
                'members' => $members,
                'dynamic' => $dynamic,
                'externalizable' => $externalizable,
            ];
            $definition = $this->definitions[array_key_last($this->definitions)];
        } else {
            $definitionIndex = $traitInfo >> 1;
            if (!isset($this->definitions[$definitionIndex])) {
                throw new RuntimeException("Undefined AMF3 trait reference {$definitionIndex}.");
            }
            $definition = $this->definitions[$definitionIndex];
        }

        if ($definition['externalizable']) {
            $this->readValue(null);
            return;
        }

        foreach ($definition['members'] as $member) {
            $this->readValue($member);
        }

        if (!$definition['dynamic']) {
            return;
        }

        while (($key = $this->readString()) !== '') {
            $this->readValue($key);
        }
    }

    private function readVector(int $marker): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 0) {
            return;
        }

        $count = $handle >> 1;
        $this->appendBytes(1); // fixed flag
        if ($marker === 0x0D || $marker === 0x0E) {
            $this->appendBytes($count * 4);
        } elseif ($marker === 0x0F) {
            $this->appendBytes($count * 8);
        } else {
            $this->readString(); // vector element type name
            for ($i = 0; $i < $count; $i++) {
                $this->readValue(null);
            }
        }
    }

    private function readDictionary(): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 0) {
            return;
        }

        $count = $handle >> 1;
        $this->appendBytes(1); // weak keys flag
        for ($i = 0; $i < $count; $i++) {
            $this->readValue(null);
            $this->readValue(null);
        }
    }

    private function readByteArrayLike(): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 1) {
            $this->appendBytes($handle >> 1);
        }
    }

    private function readReferenceOrInlineObject(int $inlineLength): void
    {
        $handle = $this->readU29();
        $this->appendU29($handle);
        if (($handle & 1) === 1) {
            $this->appendBytes($inlineLength);
        }
    }

    private function readString(?string $fieldName = null): string
    {
        $handle = $this->readU29();
        if (($handle & 1) === 0) {
            $index = $handle >> 1;
            if (!array_key_exists($index, $this->stringOffsets)) {
                throw new RuntimeException("Undefined AMF3 string reference {$index}.");
            }

            $value = $this->inputString($index);
            if ($fieldName === 'limitedEnd' && $this->isExpired($value)) {
                $this->appendInlineString($this->replaceYear($value));
                $this->patchedStrings[$index] = true;
                $this->patchedCount++;
            } elseif (isset($this->patchedStrings[$index]) && $fieldName !== 'limitedEnd') {
                // Preserve a shared limitedStart or unrelated date value.
                $this->appendInlineString($value);
            } else {
                if (!array_key_exists($index, $this->outputStringIndexes)) {
                    throw new RuntimeException("Missing output AMF3 string reference {$index}.");
                }
                $this->appendU29($this->outputStringIndexes[$index] << 1);
            }

            return $value;
        }

        $length = $handle >> 1;
        $valueOffset = $this->offset;
        $value = $length === 0 ? '' : substr($this->raw, $valueOffset, $length);
        $this->skip($length);

        if ($length === 0) {
            $this->appendU29(1);
            return '';
        }

        $index = count($this->stringOffsets);
        $this->stringOffsets[$index] = $valueOffset;
        $this->stringLengths[$index] = $length;

        if ($fieldName === 'limitedEnd' && $this->isExpired($value)) {
            $this->appendInlineString($this->replaceYear($value));
            $this->patchedStrings[$index] = true;
            $this->outputStringIndexes[$index] = $this->outputStringCount - 1;
            $this->patchedCount++;
        } else {
            $this->appendInlineString($value);
            $this->outputStringIndexes[$index] = $this->outputStringCount - 1;
        }

        return $value;
    }

    private function inputString(int $index): string
    {
        return substr($this->raw, $this->stringOffsets[$index], $this->stringLengths[$index]);
    }

    private function isExpired(string $value): bool
    {
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!n/j/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}");
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date < $this->cutoff;
    }

    private function replaceYear(string $value): string
    {
        return substr($value, 0, -4) . $this->targetYear;
    }

    private function appendInlineString(string $value): void
    {
        $this->appendU29((strlen($value) << 1) | 1);
        $this->output .= $value;
        $this->outputStringCount++;
    }

    private function appendU29(int $value): void
    {
        if ($value < 0x80) {
            $this->output .= chr($value);
        } elseif ($value < 0x4000) {
            $this->output .= chr(($value >> 7) | 0x80) . chr($value & 0x7F);
        } elseif ($value < 0x200000) {
            $this->output .= chr(($value >> 14) | 0x80)
                . chr((($value >> 7) & 0x7F) | 0x80)
                . chr($value & 0x7F);
        } else {
            $this->output .= chr(($value >> 22) | 0x80)
                . chr((($value >> 15) & 0x7F) | 0x80)
                . chr($value & 0xFF);
        }
    }

    private function readU29(): int
    {
        $value = 0;
        for ($i = 0; $i < 4; $i++) {
            $byte = $this->readByte();
            if ($i < 3) {
                $value = ($value << 7) | ($byte & 0x7F);
                if (($byte & 0x80) === 0) {
                    return $value;
                }
            } else {
                return ($value << 8) | $byte;
            }
        }

        throw new RuntimeException('Malformed AMF3 U29 integer.');
    }

    private function readByte(): int
    {
        if ($this->offset >= strlen($this->raw)) {
            throw new RuntimeException('Unexpected end of optimized item AMF.');
        }

        return ord($this->raw[$this->offset++]);
    }

    private function appendBytes(int $length): void
    {
        if ($length < 0 || $this->offset + $length > strlen($this->raw)) {
            throw new RuntimeException('Optimized item AMF contains an invalid length.');
        }

        $this->output .= substr($this->raw, $this->offset, $length);
        $this->offset += $length;
    }

    private function skip(int $length): void
    {
        if ($length < 0 || $this->offset + $length > strlen($this->raw)) {
            throw new RuntimeException('Optimized item AMF contains an invalid length.');
        }

        $this->offset += $length;
    }
}

$totalXmlPatched = 0;
foreach ($xmlFiles as $file) {
    if (!is_file($file['path'])) {
        throw new RuntimeException("Item catalog file not found: {$file['path']}");
    }

    $contents = file_get_contents($file['path']);
    if ($contents === false) {
        throw new RuntimeException("Could not read item catalog: {$file['path']}");
    }

    $xml = $file['compressed'] ? @gzuncompress($contents) : $contents;
    if ($xml === false) {
        throw new RuntimeException("Could not decompress item catalog: {$file['path']}");
    }

    [$xml, $count] = $patchXml($xml);
    $output = $file['compressed'] ? gzcompress($xml, 9) : $xml;
    if ($output === false || file_put_contents($file['path'], $output) === false) {
        throw new RuntimeException("Could not write item catalog: {$file['path']}");
    }
    $totalXmlPatched += $count;
}

$optimizedPath = $basePath . '/items_opt.amf';
$optimizedContents = is_file($optimizedPath) ? file_get_contents($optimizedPath) : false;
if ($optimizedContents === false) {
    throw new RuntimeException("Optimized item catalog is missing: {$optimizedPath}");
}

$optimized = @gzuncompress($optimizedContents);
if ($optimized === false) {
    throw new RuntimeException("Could not decompress optimized item catalog: {$optimizedPath}");
}

$amfRewriter = new ExpiredItemAmfRewriter($optimized, $cutoff, $targetYear);
[$optimized, $amfPatched] = $amfRewriter->rewrite();
$optimizedOutput = gzcompress($optimized, 9);
if ($optimizedOutput === false || file_put_contents($optimizedPath, $optimizedOutput) === false) {
    throw new RuntimeException("Could not write optimized item catalog: {$optimizedPath}");
}

fwrite(STDOUT, sprintf(
    "Extended %d XML limitedEnd values and %d optimized AMF values to %s.\n",
    $totalXmlPatched,
    $amfPatched,
    $targetYear,
));
