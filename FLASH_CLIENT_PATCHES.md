# Flash client patches

## Lonely Animal progress callback guard

`LonelyAnimal.onTransactionComplete()` could dereference incomplete feature XML,
friend-set state, or timing data while the startup AMF batch was still being
initialized. The resulting Error #1009 stopped `TransactionManager` from
finishing the rest of that batch, which could leave later client actions
unsubmitted even though the server was healthy.

The client now treats incomplete Lonely Animal state as an unavailable cosmetic
feature and isolates callback exceptions so one optional feature cannot abort
the remaining batch. The patched SWF is served as
`FarmGame-10-lonelyanimalguard1.swf` to force the Flash preloader to fetch the
new client.

## Turbo Combine coin pre-check

`GameMode.GMCombineAll.performResourceCheck()` blocked Turbo Combine locally
when the selected plots' seed cost exceeded the player's coin balance. The
bulk combine request itself is handled by `EquipmentWorldService` and does not
charge coins, so the client check only prevented an otherwise valid action.

The patched `FarmGame-10.swf` removes only that coin-balance branch. Turbo
chargers, fuel, world currency, and seed requirements remain enforced. The
revision is served as `FarmGame-10-turbocombinefree1.swf` so the legacy Flash
preloader cannot retain the previous client through its immutable SWF cache.

## Market items scoped to the active farm

`Managers.FarmGameSettingsManager.getFarmItemsMergedByWorld()` first obtains
the current farm's catalog, then deliberately appended entries from every
other world (including items whose other-world license had been acquired).
That made themed catalogs such as Winter Fable and Haunted Hollow appear in
every farm's market.

The patched merge retains the current world's items and `ANY_WORLD` items only.
Both market-search paths now apply the same restriction, so searching cannot
surface an item belonging to a different farm. The special world-specific sale
path remains intact for its dedicated sale tab. The client is served as
`FarmGame-10-marketbyworld2.swf`; a new filename is required so the preloader
cannot reuse the prior immutable SWF.

## Market search for global items

### Symptom

Searching the market for `plaza` returned no results, even though the catalog
contained Plaza Tile, Plaza Mosaic Tile, and `adobe_plaza`. The same items were
available when browsing the market normally.

The expiration-date repair was not sufficient: `adobe_plaza` had its
`limitedEnd` changed from `8/12/2010` to `12/31/2099`, and the server served the
updated catalog, but the old client still filtered it out during a search.

### Root cause

The non-optimized search in
`Managers.FarmGameSettingsManager.getFarmItemsArray()` used this condition:

```actionscript
farmItem.isVisible &&
(farmItem.worldRestrictions.indexOf(Global.worldManager.currentWorldType) != -1 ||
 farmItem.worldRestrictions.indexOf(ANY_WORLD) != -1)
```

The optimized ternary-tree search in `Widgets.Windows.Market.MarketWindow`
performed the equivalent check. Items without a `WorldRequirement` have an
empty `worldRestrictions` array, so both paths rejected them. That contradicted
the client’s `FarmItem.meetsWorldRestrictions()` implementation, which treats
an empty restriction list as globally available.

### Fix and delivery

Both search paths now call `farmItem.meetsWorldRestrictions()` (or
`matchedItem.meetsWorldRestrictions()`). World-specific items still require a
matching world, while items with no world restriction are searchable on every
farm.

The patched SWF was rebuilt with JPEXS Free Flash Decompiler 26.2.1 and
deployed as the cache-busted revision
`FarmGame-10-marketsearchworld1.swf`. The revision is routed through
`public/.htaccess`, and `resources/views/game.blade.php` selects it for new
sessions.

### Verification

After deployment, the revisioned SWF returned HTTP 200 and its SHA-256 matched
the locally verified build. A hard refresh followed by searching `plaza`
returned Plaza Tile, Plaza Mosaic Tile, Adobe Plaza, and related items.

## Gopher Garden progression during world attachment

`GopherImageProgressionFObject` can be asked to redraw a placed Gopher Garden
while `CaptureFeatureManager` is still initializing. The released client
assumed that the capture component and its `capturedCount` object already
existed, so a reload could raise Error #1009 from `getCapturedBreakdown()` and
stop the farm from loading. The targeted client patch treats that transient
state as an empty count, allowing the building to render at level zero; the
normal saved count is still used once the feature data is available.

The patched SWF is served as `FarmGame-10-gopherprogressguard3.swf` and keeps
the existing server-side capture data and progression behavior unchanged.

## Farm expansion: rejected cash purchase

`FarmGame-10.swf` contains `Transactions.TExpandFarm`. The original transaction
opened a modal progress window before calling `FarmService.expandFarm`, but did
not override `onFault`. The service correctly returns an error such as `Not
enough cash to expand the farm.`, yet the modal was never closed and appeared to
freeze the game.

The patched `TExpandFarm.onFault` closes that progress window and shows the
server-provided error in a normal OK dialog. It does not modify balances, farm
size, or the successful-expansion path.

The patch was compiled and then re-exported with JPEXS Free Flash Decompiler
26.2.1 to verify that the resulting SWF contains the fault handler.

## Farm actions: send completed state changes without the normal batch delay

### Symptom

A plot can appear plowed locally, then revert after an immediate reload. The
server-side persistence path is not the cause when it receives the request:
an audit of `WorldService.performAction` showed that a received `plow` action
commits the matching world-object row. During a short-reload reproduction no
`plow` request reached the server at all; after allowing the client to remain
open, the same action was received and persisted.

### Verified client path

The shipped `FarmGame-10.swf` follows this path for a normal manual plow:

```text
GMMultiPlow.handleClick()
  -> AMPlow (avatar travel and plow animation)
  -> Plot.plow()
  -> TransactionManager.addTransaction(new TPlow(...))
  -> TPlow.perform()
  -> WorldService.performAction("plow", ...)
```

`AMPlow` intentionally waits until the avatar action is ready before it calls
`Plot.plow()`. That timing is preserved. The avoidable delay is after that
call: `TransactionManager.addTransaction()` normally queues the transaction,
and the manager's periodic batch sender may wait up to five seconds before
sending the first AMF batch.

### Targeted change

The first patch changed the manual `Classes.Plot.plow()` enqueue call:

```actionscript
// Existing
TransactionManager.addTransaction(new TPlow(this, energySource, energy, energyMetaData));

// Patched
TransactionManager.addTransaction(new TPlow(this, energySource, energy, energyMetaData), true);
```

The second argument makes `TransactionManager` call its send routine
immediately. It does not send before the avatar action or alter the action's
costs or state transition.

The scope was subsequently extended only to farm state mutations that a player
could lose by reloading immediately after the visual action completes:

- manual plot actions: plow, clear withered, clear, harvest, and plant;
- vehicle actions: plow, plot removal, plant, harvest, and combine.

All other transactions remain batched. In particular, social, gift, reward,
onboarding, targeting, and post-load work must not be changed merely to make
them send sooner.

### Investigation method

This conclusion was obtained from the released SWF, rather than inferred from
the PHP implementation:

1. JPEXS Free Flash Decompiler 26.2.1 listed AS3 classes from
   `FarmGame-10.swf` and selectively exported `GMMultiPlow`, `AMPlow`,
   `Classes.Plot`, `Transactions.TPlow`, and
   `Engine.Managers.TransactionManager`.
2. The exported ActionScript established the call path above and showed that
   `TPlow.perform()` calls `WorldService.performAction` with action `plow`.
3. `TransactionManager.addTransaction(transaction, true)` was verified to
   invoke its send routine immediately; the default `false` path relies on a
   one-second timer and a five-second maximum wait before the initial batch
   send.
4. Server-side plow audit logs were used only to confirm the distinction
   between “request never sent” and “request sent but failed to persist.”

After editing, re-export the SWF and decompile the patched `Classes.Plot` and
`AvatarMode.AMMultiPlotAction` once to confirm the added `true` arguments.
Regression-test each affected manual action and one vehicle action: wait for
the animation to complete, reload immediately, and confirm the state remains.

### Patch/repack workflow

The original FarmVille ActionScript source tree is not available in this
repository, so this is a targeted SWF patch, not a full source rebuild. JPEXS
can compile imported ActionScript back into the matching SWF. This was smoke
tested against the current `FarmGame-10.swf`: a selectively exported script
folder re-imported successfully into a temporary SWF which retained the
expected `Classes.Plot`, `Transactions.TPlow`, and
`Engine.Managers.TransactionManager` classes.

Use a temporary workspace and keep the original SWF unchanged until the
verification pass succeeds:

```powershell
$ffdec = 'C:\path\to\ffdec-cli.exe'
$swf = 'public/farmville/embeds/Flash/v855037.855026/FarmGame-10.swf'
$work = Join-Path $env:TEMP 'fv-plow-patch'
$patched = Join-Path $work 'FarmGame-10.patched.swf'

New-Item -ItemType Directory -Force -Path $work | Out-Null
& $ffdec -config parallelSpeedUp=false `
  -selectclass 'Classes.Plot' -export script $work $swf

# Edit $work\scripts\Classes\Plot.as as shown above.
& $ffdec -config parallelSpeedUp=false `
  -importScript $swf $patched (Join-Path $work 'scripts')

# Confirm the output is readable and contains the patched source.
& $ffdec -config parallelSpeedUp=false `
  -selectclass 'Classes.Plot' -export script $work $patched
rg -n 'new TPlow\(this,energySource,energy,energyMetaData\),true' `
  (Join-Path $work 'scripts\Classes\Plot.as')
```

Only after that check and the in-game reload regression pass should the
temporary patched file replace the tracked SWF in a focused client-patch
commit. The JPEXS import takes noticeably longer than export for this SWF;
that is expected.

### Client delivery: use a filename revision, not a query string

`public/.htaccess` marks SWFs immutable for one year. More importantly, the
shipped `FV_Preloader.swf` derives its cached game revision from the
`FarmGame...swf` filename and ignores the query string. A URL such as
`FarmGame-10.swf?plow_dispatch=1` therefore is not a reliable way to deliver a
patched client: a browser or the legacy preloader may continue running the
old bytes.

Give every changed game SWF a new filename revision. The repository keeps one
tracked binary and maps the revisioned public URL to it in `public/.htaccess`:

```apache
RewriteRule ^farmville/embeds/Flash/v855037\.855026/FarmGame-10-plowdispatch1\.swf$ farmville/embeds/Flash/v855037.855026/FarmGame-10.swf [L]
```

Then point `swfLocation` in `resources/views/game.blade.php` at the same
revisioned filename:

```text
/farmville/embeds/Flash/v855037.855026/FarmGame-10-farmactiondispatch2.swf?restore_original=1
```

For the next client change, use a new descriptive revision name in both places
(for example, `FarmGame-10-nextfix1.swf`). Copy the changed SWF, the view, and
`.htaccess` into the running container, then run `php artisan view:clear`.
Apache reads `.htaccess` per request, so no container restart is required.

This delivery path was validated with the plow patch: after the filename
revision was introduced, the normal walking-avatar plow sent its AMF action
and survived the following reload.

## Empty travel worlds: load terrain when no objects exist

### Symptom

Traveling to a newly claimed Lighthouse Cove could show the Cove name and
player HUD while rendering only the default green grass plane. The Cove world
record and its `fisherman` tile set were present; the world simply had no
placed objects yet.

### Root cause and targeted change

The released `Managers.WorldManager.onUserInit` only called
`Global.world.loadObject(resultData.world)` when
`worldData.objectsArray.length > 0`. An empty world therefore remained in the
default world initialized earlier in `WorldInit`, so its world metadata and
terrain theme were never applied.

The patch keeps the existing validity guard but removes the length test:

```actionscript
if(Boolean(worldData.objectsArray))
{
   Global.world.loadObject(worldData);
   expansionData = Global.farmGameSettingsManager.getExpansionData(this.currentWorldType);
   this.m_currentWorldPlotLimits = expansionData ? expansionData.plotLimits : null;
}
```

This preserves the existing behavior for populated worlds and lets an empty
world construct its map, background, and tile set. It does not create any
objects or alter the saved world.

### FFDec patch/repack verification

`Managers.WorldManager` was exported from `FarmGame-10.swf`, patched, and
imported into a separate SWF. Re-exporting that class from the rebuilt SWF
confirmed that `Global.world.loadObject(worldData)` is reached whenever
`objectsArray` exists, including an empty array.

The client is delivered under the new revisioned URL
`FarmGame-10-coveemptyworld1.swf`; `public/.htaccess` maps that URL to the
tracked patched SWF and `resources/views/game.blade.php` selects it. The
revision is required because the legacy preloader treats game SWFs as
immutable and may ignore query-string-only cache busting.

## Fuel refill harvest rewards and Gift Box count

### Symptom and route

The fuel-can quantity dialog is opened by `Widgets.Slots.GiftBox.GiftBoxSlot`.
For a fuel item it passes `m_data.quantity` to
`UseAllAmountSelectionWindow`, while the quantity input independently starts
at `1`.  A newly harvested pump refill could therefore show `x0` beside the
fuel icon even though the server had just written the refill to the Gift Box.
The selected fuel can then follows `Classes.ZItem.FuelItem.onUse` through
`Transactions.TBuyFuel.perform` to `FarmService.buyFuel(itemName, true)`.

The server now returns an authoritative Gift Box snapshot with a successful
harvest reward.  The client patch applies that snapshot in both relevant
completion paths:

- `Transactions.TWorldState.onComplete` refreshes the Gift Box after a normal
  `WorldService.performAction("harvest", ...)` response;
- `Transactions.TEquipmentAction.onComplete` refreshes it after a bulk
  `EquipmentWorldService.onUseEquipment("harvest", ...)` response.

The refresh is guarded to run once for a bulk harvest, including the combined
harvest/plow/plant response.  The fuel-use request itself remains on the
existing `FarmService.buyFuel` route; the fix corrects the stale pre-submit
Gift Box count and the missing bulk-harvest reward.

### FFDec patch/repack verification

The patch was exported from and imported into the tracked
`FarmGame-10.swf` with JPEXS Free Flash Decompiler 26.2.1.  Re-exporting
`Transactions.TWorldState` and `Transactions.TEquipmentAction` from the
patched file confirmed both `Global.player.refreshGiftBox(...)` handlers.
The new client is served as
`FarmGame-10-fuelrefill1.swf`; the revisioned URL is mapped to the tracked
SWF in `public/.htaccess` and is selected by `resources/views/game.blade.php`.

## World-score persistence

The Flash client keeps world score in `Player.worldScores`, but the server's
InitUser payload did not include that map. Quest score rewards were also being
stored under `world_score_main` when the caller omitted the optional world
argument. The server now returns the saved score and level for each unlocked or
active world, resolves omitted quest rewards against `currentWorldType`, and
persists the level reported by `TWorldScoreLevelUp`.

`Transactions.TWorldScoreLevelUp` was patched so its
`updateWorldScoreLevelUp` call sends the score unit, current level, and current
score. `Player.addWorldScore` now queues that sync after every positive score
gain, rather than only when a level-up occurs. The resulting client is served
as `FarmGame-10-worldscorepersist2.swf`; the revisioned URL is mapped to the
tracked SWF in `public/.htaccess` and selected by `resources/views/game.blade.php`.

## World-score persistence transaction coalescing

`Player.addWorldScore` must keep the score persistent, but a Turbo Combine
updates the local score once per affected plot. The persistence patch now
coalesces `TWorldScoreLevelUp` transactions by score unit: one request may be
queued or in flight at a time, and completion schedules one follow-up only
when the score changed while that request was running. This prevents a large
combine from filling the Flash client's 50-transaction queue while preserving
the final score and level.

The resulting client is served as `FarmGame-10-worldscorecoalesce1.swf`.

## Emerald Valley planting and plowing world score

Emerald Valley is internally named `oz`, but its expansion configuration uses
the score unit `rainbowPoints`. The server previously generated `ozPoints`,
which the Emerald Valley HUD never reads. The score-unit mapping now returns
`rainbowPoints` and resolves that unit back to `oz` for persistence.

Valid plows and crop plantings in Emerald Valley now grant the same base XP
amount to the Emerald Valley score as to normal farmer XP. The server awards
and atomically persists that score only after the authoritative plot write and
resource transaction succeed. `UserService` accepts the client-reported level
for the original HUD flow but ignores a client-reported `rainbowPoints` score,
so that callback cannot overwrite or double the server award.

The client adds the matching local `rainbowPoints` amount in `Plot.plow()` and
`Plot.plant()` so the world meter refreshes immediately. Its existing
coalesced score transaction saves the calculated level. The client is served
as `FarmGame-10-emeraldscore1.swf`.

## Witcher Hut shadow-only rendering

The Sleepy Hollow Witcher Hut is a `CraftingCottageBuilding` with craft type
`xshcrafttype`. The saved object is fully built and its SWF asset is present,
but the normal-world client looked up a player craft-state entry that does not
exist for this event-only craft type. That returned craft level `0`, causing
`StorageBuilding` to request `construct_0`; the catalog only provides
`built_0` through `built_4`, so the client rendered the placement shadow alone.

`CraftingCottageBuilding` now falls back to the object's saved `craftLevel`
(level 1 for the existing Witcher Hut) and safely reports one slot when no
craft-state/config entry exists. The resulting client is served as
`FarmGame-10-witcherhut1.swf`; the revisioned URL is mapped to the tracked SWF
in `public/.htaccess` and selected by `resources/views/game.blade.php`.

## Optional terrain coordinate overlay

The Jade Falls terrain coordinate labels are now controlled by the Account
Settings checkbox `Show map coordinates`. The preference is stored per player
under the `show_terrain_coordinates` metadata key; an absent key is treated as
`false`, so existing and new players start with the overlay hidden.

The game view passes the preference as the `fv_show_terrain_coordinates`
FlashVar. `InvisibleTerrainMap` reads it when constructed and also checks it
at render time, so stale debug state cannot draw labels while the preference is
off. The existing context-menu toggle is available only when the preference or
the developer terrain-mapping experiment is enabled. The patched client is
served as `FarmGame-10-terraincoordinates2.swf`; the revisioned URL is mapped
to the tracked SWF in `public/.htaccess` and selected by
`resources/views/game.blade.php`.

The SWF was exported and re-imported with JPEXS Free Flash Decompiler 26.2.1,
then re-exported to confirm the FlashVar and context-menu changes.
