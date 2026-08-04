<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\User;
use Illuminate\Console\Command;

class RestoreInventoryItem extends Command
{
    protected $signature = 'user:restore-inventory
        {uid : Numeric player UID to restore}
        {item : Internal item name or FarmVille item code}
        {quantity : Number of copies to restore}
        {--dry-run : Show the resolved item without changing the account}';

    protected $description = 'Restore lost items to a player Home Inventory (administrator recovery tool)';

    public function handle(): int
    {
        $uid = (int) $this->argument('uid');
        $itemIdentifier = trim((string) $this->argument('item'));
        $quantity = (int) $this->argument('quantity');

        if ($uid <= 0 || $itemIdentifier === '' || $quantity <= 0) {
            $this->error('UID, item, and a positive quantity are required.');

            return self::FAILURE;
        }

        if (! User::where('uid', $uid)->exists()) {
            $this->error("No player exists with UID {$uid}.");

            return self::FAILURE;
        }

        $item = Item::where('name', $itemIdentifier)
            ->orWhere('code', $itemIdentifier)
            ->first();

        if (! $item) {
            $this->error("No item matches '{$itemIdentifier}'. Use its internal name or code from the items table.");

            return self::FAILURE;
        }

        $this->line("Resolved {$item->name} ({$item->code}); restoring {$quantity} to UID {$uid}.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $rawStorage = PlayerMeta::getValue($uid, 'inventory_storage');
        $storage = is_string($rawStorage) ? @unserialize($rawStorage) : [];
        if (! is_array($storage)) {
            $storage = [];
        }

        $code = (string) $item->code;
        if (! isset($storage[$code]) || ! is_array($storage[$code])) {
            $storage[$code] = [0, [], []];
        }

        $storage[$code][0] = max(0, (int) ($storage[$code][0] ?? 0)) + $quantity;
        $storage[$code][1] = is_array($storage[$code][1] ?? null) ? $storage[$code][1] : [];
        $storage[$code][2] = is_array($storage[$code][2] ?? null) ? $storage[$code][2] : [];

        PlayerMeta::setValue($uid, 'inventory_storage', serialize($storage));

        $this->info("Restored {$quantity} {$item->name} item(s). The player can reload and place them from Home Inventory.");

        return self::SUCCESS;
    }
}
