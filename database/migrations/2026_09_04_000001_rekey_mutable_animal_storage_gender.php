<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make mutable-animal storage code agree with the DNA saved beside it.
     *
     * The old Flash storage request supplied the compact item code and the
     * server persisted that code before per-animal DNA was introduced.  A
     * stale I!/H! prefix can therefore disagree with DNA.G after a reload.
     * DNA is authoritative; this migration moves the metadata, counts, and
     * featured slots to the matching gender code without changing the DNA or
     * its existing hash.
     */
    public function up(): void
    {
        $pairs = $this->animalCodePairs();
        $stats = [
            'objects_scanned' => 0,
            'objects_updated' => 0,
            'entries_rekeyed' => 0,
            'entries_hashed' => 0,
            'featured_slots_rekeyed' => 0,
            'unresolved_entries' => 0,
        ];

        DB::table('world_objects')
            ->whereNotNull('components')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($pairs, &$stats): void {
                foreach ($rows as $row) {
                    ++$stats['objects_scanned'];
                    $components = $this->decodeObject($row->components ?? null);
                    if ($components === null || !property_exists($components, 'storageMetadata')) {
                        continue;
                    }

                    $storageMetadata = $this->decodeObject($components->storageMetadata ?? null);
                    if ($storageMetadata === null) {
                        continue;
                    }

                    $contents = $this->decodeList($row->contents ?? null);
                    $contentsBefore = $contents;
                    $metadataBefore = json_encode($storageMetadata, JSON_UNESCAPED_SLASHES);
                    $featuredBefore = property_exists($components, 'featuredItems')
                        ? json_encode($components->featuredItems, JSON_UNESCAPED_SLASHES) : null;

                    $rekeyedMetadata = new \stdClass();
                    $moves = [];
                    $availableHashes = [];

                    foreach (get_object_vars($storageMetadata) as $metadataKey => $entries) {
                        [$oldCode, $keyHash] = array_pad(explode(':', (string) $metadataKey, 2), 2, '');
                        $entryList = is_array($entries) ? $entries : [$entries];

                        foreach ($entryList as $entry) {
                            $dna = $this->decodeDna($entry);
                            $gender = $dna !== null
                                ? strtoupper((string) ($dna['G'] ?? '')) : '';
                            $family = $this->animalFamily($oldCode, $pairs);
                            $newCode = $family !== null && isset($pairs[$family][$gender])
                                ? $pairs[$family][$gender] : $oldCode;
                            $hash = $keyHash !== '' ? $keyHash : ($dna !== null ? $this->dnaHash($dna) : null);

                            if ($family === null || !in_array($gender, ['M', 'F'], true)) {
                                ++$stats['unresolved_entries'];
                            }
                            if ($keyHash === '' && $hash !== null) {
                                ++$stats['entries_hashed'];
                            }

                            $hasAuthoritativeGender = $family !== null
                                && in_array($gender, ['M', 'F'], true);
                            $newKey = $hash !== null || $hasAuthoritativeGender
                                ? $newCode . ':' . ($hash ?? '')
                                : (string) $metadataKey;
                            $value = $this->rawMetadataValue($entry);
                            $existing = $rekeyedMetadata->{$newKey} ?? [];
                            $existing = is_array($existing) ? $existing : [];
                            $existing[] = $value;
                            $rekeyedMetadata->{$newKey} = $existing;

                            if ($newCode !== $oldCode && $family !== null && in_array($gender, ['M', 'F'], true)) {
                                ++$stats['entries_rekeyed'];
                                $moves[] = [$oldCode, $newCode];
                            }

                            if ($family !== null && in_array($gender, ['M', 'F'], true) && $hash !== null) {
                                $oldFullHash = $oldCode . ':' . $hash;
                                $newFullHash = $newCode . ':' . $hash;
                                $availableHashes[$newFullHash] = ($availableHashes[$newFullHash] ?? 0) + 1;
                                $moves['hashes'][$oldFullHash][] = $newFullHash;
                                if ($keyHash === '') {
                                    $moves['generic'][$oldCode][] = $newFullHash;
                                }
                            }
                        }
                    }

                    if ($moves !== []) {
                        $contents = $this->applyContentMoves($contents, $moves);
                        $contents = $this->ensureMetadataCounts($contents, $rekeyedMetadata, $pairs);
                    }

                    $featured = $this->decodeObject($components->featuredItems ?? null) ?? new \stdClass();
                    $featuredChanged = false;
                    $usedHashes = [];
                    foreach (get_object_vars($featured) as $slot => $entry) {
                        $itemCode = $this->field($entry, 'itemCode');
                        $metaHash = $this->field($entry, 'metaHash');
                        $replacement = null;
                        if (is_string($metaHash) && isset($moves['hashes'][$metaHash])) {
                            $replacement = array_shift($moves['hashes'][$metaHash]);
                        } elseif (is_string($metaHash) && str_ends_with($metaHash, ':')) {
                            $baseCode = substr($metaHash, 0, -1);
                            if (isset($moves['generic'][$baseCode])) {
                                $replacement = array_shift($moves['generic'][$baseCode]);
                            }
                        }

                        if ($replacement !== null) {
                            $replacementCode = explode(':', $replacement, 2)[0];
                            if (is_object($entry)) {
                                $entry->itemCode = $replacementCode;
                                $entry->metaHash = $replacement;
                            } else {
                                $entry['itemCode'] = $replacementCode;
                                $entry['metaHash'] = $replacement;
                            }
                            $featured->{$slot} = $entry;
                            ++$stats['featured_slots_rekeyed'];
                            $featuredChanged = true;
                            $usedHashes[$replacement] = ($usedHashes[$replacement] ?? 0) + 1;
                        } elseif (is_string($metaHash) && isset($availableHashes[$metaHash])) {
                            $usedHashes[$metaHash] = ($usedHashes[$metaHash] ?? 0) + 1;
                        }

                        // If a slot has a stale code but no matching metadata
                        // hash, leave it for the normal load-time reconciler.
                        // Removing an unidentifiable slot here could hide a
                        // valid legacy animal.
                        unset($itemCode);
                    }

                    // Add any DNA-backed animals that had no featured slot.
                    // This makes the migration self-contained instead of
                    // depending on a later Flash write to render the animal.
                    $nextSlot = 0;
                    foreach ($availableHashes as $fullHash => $count) {
                        $missing = $count - ($usedHashes[$fullHash] ?? 0);
                        for ($i = 0; $i < $missing; ++$i) {
                            while (property_exists($featured, (string) $nextSlot)) {
                                ++$nextSlot;
                            }
                            $featured->{(string) $nextSlot} = (object) [
                                'itemCode' => explode(':', $fullHash, 2)[0],
                                'metaHash' => $fullHash,
                            ];
                            ++$nextSlot;
                            $featuredChanged = true;
                        }
                    }

                    $components->storageMetadata = $rekeyedMetadata;
                    if ($featuredChanged || $featuredBefore !== json_encode($featured, JSON_UNESCAPED_SLASHES)) {
                        $components->featuredItems = $featured;
                    }

                    $metadataAfter = json_encode($rekeyedMetadata, JSON_UNESCAPED_SLASHES);
                    $contentsAfter = json_encode($contents, JSON_UNESCAPED_SLASHES);
                    $featuredAfter = property_exists($components, 'featuredItems')
                        ? json_encode($components->featuredItems, JSON_UNESCAPED_SLASHES) : null;
                    if ($metadataBefore === $metadataAfter
                        && json_encode($contentsBefore, JSON_UNESCAPED_SLASHES) === $contentsAfter
                        && $featuredBefore === $featuredAfter) {
                        continue;
                    }

                    $encodedComponents = json_encode($components, JSON_UNESCAPED_SLASHES);
                    if ($encodedComponents === false) {
                        throw new \RuntimeException('Unable to encode mutable-animal storage for world object ' . $row->id);
                    }

                    DB::table('world_objects')
                        ->where('id', $row->id)
                        ->update([
                            'components' => $encodedComponents,
                            'contents' => json_encode($contents, JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    ++$stats['objects_updated'];
                }
            });

        Log::info('Rekeyed mutable-animal storage metadata by authoritative DNA', $stats);
    }

    public function down(): void
    {
        // The original gender code is not authoritative and cannot be
        // reconstructed safely after this migration.
    }

    private function animalCodePairs(): array
    {
        $pairs = [
            'pigpen' => ['M' => 'H!', 'F' => 'I!'],
            'sheeppen' => ['M' => 'sheeppen_ram', 'F' => 'sheeppen_ewe'],
            '_aliases' => [
                'H!' => 'pigpen',
                'I!' => 'pigpen',
                'pigpen_male' => 'pigpen',
                'pigpen_female' => 'pigpen',
                'sheeppen_ram' => 'sheeppen',
                'sheeppen_ewe' => 'sheeppen',
            ],
        ];

        if (!Schema::hasTable('items')) {
            return $pairs;
        }

        // Use only the canonical adult rows. Variant rows (for example,
        // pigpen_male_light_green) can have different artwork codes and must
        // not replace the base male/female code used by pen storage.
        foreach (DB::table('items')
            ->where(function ($query): void {
                $query->where('name', 'like', 'pigpen_male%')
                    ->orWhere('name', 'like', 'pigpen_female%')
                    ->orWhere('name', 'like', 'sheeppen_ram%')
                    ->orWhere('name', 'like', 'sheeppen_ewe%');
            })
            ->get(['name', 'code']) as $item) {
            $name = strtolower((string) $item->name);
            $code = (string) $item->code;
            if ($code === '') {
                continue;
            }
            if (str_starts_with($name, 'pigpen_male')) {
                $pairs['_aliases'][$code] = 'pigpen';
            } elseif (str_starts_with($name, 'pigpen_female')) {
                $pairs['_aliases'][$code] = 'pigpen';
            } elseif (str_starts_with($name, 'sheeppen_ram')) {
                $pairs['_aliases'][$code] = 'sheeppen';
            } elseif (str_starts_with($name, 'sheeppen_ewe')) {
                $pairs['_aliases'][$code] = 'sheeppen';
            }
            if (str_starts_with($name, 'pigpen_male')) {
                if ($name === 'pigpen_male') {
                    $pairs['pigpen']['M'] = $code;
                }
            } elseif (str_starts_with($name, 'pigpen_female')) {
                if ($name === 'pigpen_female') {
                    $pairs['pigpen']['F'] = $code;
                }
            } elseif (str_starts_with($name, 'sheeppen_ram')) {
                if ($name === 'sheeppen_ram') {
                    $pairs['sheeppen']['M'] = $code;
                }
            } elseif (str_starts_with($name, 'sheeppen_ewe')) {
                if ($name === 'sheeppen_ewe') {
                    $pairs['sheeppen']['F'] = $code;
                }
            }
        }

        return $pairs;
    }

    private function animalFamily(string $code, array $pairs): ?string
    {
        if (isset($pairs['_aliases'][$code])) {
            return $pairs['_aliases'][$code];
        }
        foreach ($pairs as $family => $genderCodes) {
            if ($family === '_aliases') {
                continue;
            }
            if (in_array($code, $genderCodes, true)) {
                return $family;
            }
        }

        $lower = strtolower($code);
        if (str_starts_with($lower, 'pigpen_')) {
            return 'pigpen';
        }
        if (str_starts_with($lower, 'sheeppen_')) {
            return 'sheeppen';
        }

        return null;
    }

    private function decodeObject($value): ?\stdClass
    {
        if (is_string($value)) {
            $value = json_decode($value);
        } elseif (is_array($value)) {
            $value = (object) $value;
        }

        return is_object($value) ? $value : null;
    }

    private function decodeList($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values($value);
    }

    private function field($value, string $field): mixed
    {
        if (is_object($value)) {
            return $value->{$field} ?? null;
        }
        return is_array($value) ? ($value[$field] ?? null) : null;
    }

    private function rawMetadataValue($entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }
        if (is_object($entry) && isset($entry->type) && is_string($entry->type)) {
            return $entry->type;
        }
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '' : $encoded;
    }

    private function decodeDna($entry): ?array
    {
        if (is_object($entry) && isset($entry->type) && is_string($entry->type)) {
            $entry = $entry->type;
        }
        if (is_object($entry)) {
            $entry = get_object_vars($entry);
        }
        if (is_string($entry)) {
            $entry = json_decode($entry, true);
        }
        if (!is_array($entry)) {
            return null;
        }
        if (isset($entry['type']) && is_string($entry['type'])) {
            return $this->decodeDna($entry['type']);
        }
        if (isset($entry['dna']) && is_array($entry['dna'])) {
            $entry = $entry['dna'];
        }
        return isset($entry['G'], $entry['B'], $entry['P']) ? $entry : null;
    }

    private function dnaHash(array $dna): ?string
    {
        if (!isset($dna['G'], $dna['B'], $dna['P'])) {
            return null;
        }
        $state = (string) $dna['G'];
        foreach (['B', 'P'] as $trait) {
            foreach (['H', 'S', 'V'] as $channel) {
                $values = $dna[$trait][$channel] ?? ['', ''];
                $state .= ($values[0] ?? '') . ',' . ($values[1] ?? '');
            }
            if ($trait === 'P') {
                $state .= $dna['P']['T'][0] ?? '';
            }
        }
        return substr(md5($state), 0, 8);
    }

    private function applyContentMoves(array $contents, array $moves): array
    {
        $counts = [];
        $order = [];
        foreach ($contents as $content) {
            $code = $this->field($content, 'itemCode');
            $count = (int) $this->field($content, 'numItem');
            if (!is_string($code) || $code === '') {
                continue;
            }
            if (!array_key_exists($code, $counts)) {
                $order[] = $code;
                $counts[$code] = 0;
            }
            $counts[$code] += max(0, $count);
        }

        foreach ($moves as $move) {
            if (!is_array($move) || !array_is_list($move) || count($move) !== 2
                || !is_string($move[0]) || !is_string($move[1])) {
                continue;
            }
            [$oldCode, $newCode] = $move;
            if ($oldCode === $newCode) {
                continue;
            }
            $counts[$oldCode] = max(0, ($counts[$oldCode] ?? 0) - 1);
            if (!array_key_exists($newCode, $counts)) {
                $order[] = $newCode;
                $counts[$newCode] = 0;
            }
            ++$counts[$newCode];
        }

        $result = [];
        foreach ($order as $code) {
            if (($counts[$code] ?? 0) > 0) {
                $result[] = ['itemCode' => $code, 'numItem' => $counts[$code]];
            }
        }
        return $result;
    }

    private function ensureMetadataCounts(array $contents, \stdClass $metadata, array $pairs): array
    {
        $counts = [];
        $order = [];
        foreach ($contents as $content) {
            $code = $this->field($content, 'itemCode');
            if (!is_string($code) || $code === '') {
                continue;
            }
            if (!array_key_exists($code, $counts)) {
                $order[] = $code;
                $counts[$code] = 0;
            }
            $counts[$code] += max(0, (int) $this->field($content, 'numItem'));
        }

        $metadataCounts = [];
        foreach (get_object_vars($metadata) as $key => $entries) {
            [$code] = array_pad(explode(':', (string) $key, 2), 2, '');
            $family = $this->animalFamily($code, $pairs);
            if ($family === null) {
                continue;
            }
            $metadataCounts[$code] = ($metadataCounts[$code] ?? 0)
                + (is_array($entries) ? count($entries) : 1);
        }

        foreach ($metadataCounts as $code => $minimum) {
            if (!array_key_exists($code, $counts)) {
                $order[] = $code;
                $counts[$code] = 0;
            }
            $counts[$code] = max($counts[$code], $minimum);
        }

        $result = [];
        foreach ($order as $code) {
            if (($counts[$code] ?? 0) > 0) {
                $result[] = ['itemCode' => $code, 'numItem' => $counts[$code]];
            }
        }
        return $result;
    }
};
