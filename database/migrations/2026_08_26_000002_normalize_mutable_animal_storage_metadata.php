<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Convert the old AMF storage wrapper into the format expected by Flash.
     *
     * The mutable-animal renderer expects each storage entry to be a JSON DNA
     * string. An earlier persistence path stored the TStoreItem wrapper itself
     * (for example, {"type":"{...DNA...}"}); Flash stringifies that object as
     * "[object Object]" and the farm cannot load it. The write path now stores
     * the raw string, but existing rows need a one-time data repair.
     */
    public function up(): void
    {
        $updatedRows = 0;
        $normalizedEntries = 0;
        $discardedEntries = 0;

        DB::table('world_objects')
            ->whereNotNull('components')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$updatedRows, &$normalizedEntries, &$discardedEntries): void {
                foreach ($rows as $row) {
                    $components = $this->decodeObject($row->components);
                    if (!$components || !property_exists($components, 'storageMetadata')) {
                        continue;
                    }

                    $storageMetadata = $this->decodeObject($components->storageMetadata);
                    if (!$storageMetadata) {
                        continue;
                    }

                    $changed = false;
                    foreach (get_object_vars($storageMetadata) as $key => $entries) {
                        $entries = is_array($entries) ? $entries : [$entries];
                        $normalized = [];

                        foreach ($entries as $entry) {
                            $original = $entry;
                            $entry = $this->normalizeEntry($entry);

                            if ($entry === null) {
                                $discardedEntries++;
                                $changed = true;
                                continue;
                            }

                            $normalized[] = $entry;
                            if ($entry !== $original) {
                                $normalizedEntries++;
                                $changed = true;
                            }
                        }

                        if (count($normalized) !== count($entries)) {
                            $changed = true;
                        }
                        $storageMetadata->{$key} = $normalized;
                    }

                    if (!$changed) {
                        continue;
                    }

                    $components->storageMetadata = $storageMetadata;
                    $encoded = json_encode($components, JSON_UNESCAPED_SLASHES);
                    if ($encoded === false) {
                        throw new RuntimeException('Unable to encode world object ' . $row->id . ' components');
                    }

                    DB::table('world_objects')
                        ->where('id', $row->id)
                        ->update([
                            'components' => $encoded,
                            'updated_at' => now(),
                        ]);
                    $updatedRows++;
                }
            });

        Log::info('Normalized mutable-animal storage metadata', [
            'updated_rows' => $updatedRows,
            'normalized_entries' => $normalizedEntries,
            'discarded_entries' => $discardedEntries,
        ]);
    }

    public function down(): void
    {
        // The old wrapper format crashes the Flash renderer and cannot be
        // restored safely after the raw DNA strings have been persisted.
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

    /**
     * Return the raw JSON DNA string, or null for stale values that would
     * produce Flash's "Unexpected o" JSON parse error.
     */
    private function normalizeEntry($entry): ?string
    {
        if (is_object($entry)) {
            $entry = isset($entry->type) && is_string($entry->type)
                ? $entry->type
                : json_encode($entry, JSON_UNESCAPED_SLASHES);
        } elseif (is_array($entry)) {
            $entry = isset($entry['type']) && is_string($entry['type'])
                ? $entry['type']
                : json_encode($entry, JSON_UNESCAPED_SLASHES);
        }

        if (!is_string($entry)) {
            return null;
        }

        $trimmed = trim($entry);
        if ($trimmed === '' || $trimmed === 'null') {
            return $entry;
        }

        if ($trimmed[0] !== '{' || !is_array(json_decode($trimmed, true))) {
            return null;
        }

        return $trimmed;
    }
};
