# Flash client patches

## Turbo crop growth selection

`FarmGame-10-turbogrowth1.swf` is built from the deployed turtle-breeding
revision with changes to `Classes.Plot` and `GameMode.GMMultiPlotAction`.
Before equipment modes select plots, they refresh growth for the whole farm.
The normal game loop ticks only visible objects, which previously let turbo
skip mature offscreen crops whose cached state was still `planted`.

`EquipmentWorldService` also accepts legacy saved plot IDs in the temporary
range when the exact ID and coordinates match the current world. This fixes
repeatedly skipped older crops without changing new-ID allocation.
See `operational-notes/TURBO_CROP_DIAMOND_INCIDENT.md` for production evidence,
rollout status, and remaining player validation.

## Turtle Pen breeding

The Turtle Pen now uses the server breeding transaction: it validates two
stored adult turtles and their DNA, accepts the pen's asexual parent pairing,
charges love potions once, persists the session, and awards a DNA-backed baby
on completion. The baby can mature into `turtle_male`. The fixed-outcome
offspring inherits combinations of its parents' traits. Invalid or replayed
requests do not consume potions or award another baby.
Breeding sessions are keyed by their `suiteSlot` when read, including older
sparse JSON objects, and written as dense lists. This keeps slot 1 or 2
finishable after slot 0 is completed and prevents a missing slot from being
reported to Flash as a failed breeding outcome.

The client previously deducted potions before receiving the begin response.
`AnimalBreedingManager` and `TAnimalBeginBreeding` now update the local count
only after server success and display an error on rejection. The maintained
FFDec inputs are under `fv-decompiled-swf/patches/import-scripts/`, and the
rebuilt client is `FarmGame-10-turtlebreeding1.swf`. The artifact hash is in
`fv-decompiled-swf/metadata/FarmGame-10-turtlebreeding1.sha256`. These changes
are local until deployed.

## Server-authoritative plow fuel discovery

The classic `TPlow` constructor previously showed `effect_fuel_burst.swf`
when its local 2% roll succeeded, even though `WorldService.performAction`
never awarded fuel. The animation could therefore appear over a plowed plot
while the fuel gauge remained at zero.

`PlowFuelDiscovery` now runs the matching MD5/1000 roll after a successfully
paid manual plow. It enforces the archived level-12 minimum and six-hour
cooldown, and atomically grants the two fuel tanks configured as `fuelLoot2`
(`2 * energyMax` plots). The cooldown and fuel balance commit together; a
replayed plow is rejected before this grant path. No client-reported
discovery is trusted.

`Transactions.TPlow` no longer plays the effect in its constructor. On a
server-confirmed `fuelDiscovery` response it updates the local gauge and then
plays the effect. The source is maintained at
`fv-decompiled-swf/patches/import-scripts/Transactions/TPlow.as`. The revised
client was imported from `FarmGame-10-fuelmessage1.swf` with FFDec 26.2.1 and
re-exported to verify the response handler. It is served locally as
`FarmGame-10-plowfueldiscovery1.swf`; production needs a separate deployment.

## Out-of-fuel equipment warning

When a vehicle exhausts fuel, the Flash client calls
`UI.displayImpulseBuyPopup(ImpulseBuy.TYPE_FUEL, ...)`. With the unsupported
cash-purchase popup disabled, that function previously displayed the generic
`FC_POPUP_MESSAGE` ("You do not have enough Farm Cash for that item.") even
though the failed resource check was for fuel, not the owned vehicle.

`Display.UI` now shows "You are out of fuel. Refill your fuel to keep using
your equipment." for `TYPE_FUEL` only. Actual Farm Cash purchase attempts
retain `FC_POPUP_MESSAGE`. The maintained import source is
`fv-decompiled-swf/patches/import-scripts/Display/UI.as`. FFDec 26.2.1
rebuilt the client from `FarmGame-10-petlifecycle1.swf`; re-export of
`Display.UI` confirmed both message branches. The local game view selects
the cache-busted `FarmGame-10-fuelmessage1.swf` artifact. Production requires
a separate deployment.

## Pet feeding persistence

`CPetsKibble` now includes its target pet ID in the existing `use` AMF
transaction. The client applies the feed only after server acceptance and
restores its optimistically removed local food item on rejection. The
server checks that the pet belongs to the authenticated
farmer's active world, that it is due for feeding, and that one kibble/treat
is available; the food decrement and pet-state update commit together.
The patch is built from the previous `FarmGame-10-masteryrefresh1.swf` and
served as `FarmGame-10-petfood1.swf`. The maintained ActionScript input and
artifact hash are in `fv-decompiled-swf/patches` and `metadata`.

Pet placement, follow preference, daily feeding, and the client adulthood
notification are the first restoration milestone. Runaway/rescue and trick rewards still need separate work before
the original pet lifecycle is complete.

## Audio pause while the game window is minimized

The host page now watches `document.visibilityState` and calls the Flash
client's `setWindowMinimized()` callback. The client routes that state through
the existing `FarmGameWorld.pauseSoundForcefully()` and
`resetForcedSoundPause()` methods, pausing both music and sound effects while
the window is minimized without changing saved player audio preferences. A
window merely covered by another window remains audible when the browser
keeps the document visible.

The cache-busted client artifact is
`fv-decompiled-swf/artifacts/FarmGame-10-windowaudio1.swf`.

## Live market mastery counter refresh

The server returns absolute `goalCounters` after ordinary and equipment crop
harvests, and the Flash transaction handler updates `Global.player` correctly.
However, open market cards render their mastery text only during `populate()`,
so the visible `0/120` counter remained stale until the page was reloaded.

`Transactions.TFarmTransaction` now refreshes the open market window after a
mastery counter is applied. The refresh is guarded to mastery responses only,
and `UI.updateMarketWindowItems()` is a no-op when the market is closed.

The cache-busted client artifact is
`fv-decompiled-swf/artifacts/FarmGame-10-masteryrefresh1.swf`.

## Market loading with missing super-crop state

The market slot renderer evaluates `SuperCropRequirement` for seed cards. The
legacy player bootstrap was returning `superCropsStatus` as `null`, so the
client's `Player.isSuperCropUnlocked()` called `indexOf()` on a null value and
raised Error #1009 before the seed cards could render.

The server now sends an empty array for a player with no unlocked super crops.
The companion client guard is maintained in
`fv-decompiled-swf/patches/import-scripts/Classes/Player.as` and is built as
`FarmGame-10-marketloadguard1.swf`, so older or partially populated payloads
remain safe without changing unlock behavior.

## Home Inventory placement reconciliation

Home Inventory placement is optimistic in the Flash client. The server now
returns an `inventoryDelta` containing the exact storage key and authoritative
remaining quantity after a placement succeeds or is rejected. The shared
`Transactions.TWorldState` completion path applies that delta for every item,
so a stale client count cannot leave a placeable item appearing available.

`Display.InventoryWithdrawal` also preserves the exact inventory key when it
builds a market item selection. This matters for catalog aliases and
per-instance inventory keys; the server validates the key against the
requested item family before consuming it. The Bloom Garden finished/source
pair is covered by regression tests, but the behavior is intentionally
generic.

The maintained ActionScript import sources are:

- `fv-decompiled-swf/patches/import-scripts/Transactions/TWorldState.as`
- `fv-decompiled-swf/patches/import-scripts/Display/InventoryWithdrawal.as`

The rebuilt artifact is `FarmGame-10-inventorysync1.swf`, produced from
`fv-decompiled-swf/artifacts/FarmGame-10-marketcosmic2.swf` with FFDec/JPEXS
26.2.1. Its SHA-256 is recorded in the decompiled-SWF metadata. The game view
selects the new filename locally; it still requires a separate production
asset rollout.

## Cosmic world-level market prerequisite tooltip

The market prerequisite builder can request
`Dialogs:MarketCard_PrereqLocked_Rollover_world_level_min_cosmic`, but the
recovered `en_US.swf` locale catalog does not contain that entry. In debug
mode, the missing lookup appeared directly to players as `key not found`.

`Init.ZLocalizationInit` now injects the missing `Dialogs` entry immediately
after the external locale SWF loads, using the same wording pattern as the
nearby world-level entries: `Cosmic Level {quantity} needed`. The market
tooltip continues through the normal `ZLocUtils` lookup and replacement path.

The rebuilt client is served as `FarmGame-10-marketcosmic2.swf` so the legacy
Flash preloader cannot reuse the previous immutable SWF cache.

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

## Turtle Back Moats III and IV full-base rotation

`RotateableDecoration` selects an asset export named `horizontal` or `vertical`;
it does not rotate the display object itself. The recovered alternate exports
for Turtle Back Moats III and IV changed only the turtle, leaving the asymmetric
moat base in its horizontal orientation. The other Turtle Back Moats (I, II, V,
and VI) establish the expected contract: the alternate art mirrors the complete
asset, including its base.

The corrected assets live in the tracked patch directory:

- `public/farmville/patches/decorations/moat_turtleback3_rotationfix1.swf`
- `public/farmville/patches/decorations/moat_turtleback4_rotationfix1.swf`

Each preserves the original ActionScript exports and changes only the vertical
bitmap (`moat_turtleback3_vertical` or `moat_turtleback4_vertical`) to a
horizontal mirror of its matching horizontal bitmap. The catalog archive itself
remains unchanged. During image build, `scripts/patch-moat-asset-hash.php`
creates a revisioned AMF asset-hash delta that maps the two logical SWF names to
the corrected content hashes. `public/.htaccess` maps those public hashed URLs
to the tracked patch SWFs and maps `v855038-moatrotation1` back to the original
XML catalog for every other resource. The revisioned `xml_url` makes Flash fetch
the new delta instead of reusing a cached prior asset-hash response.

Verification: FFDec re-exported both modified bitmaps with zero differing
pixels from the expected full-image mirrors, and `-dumpAS3` confirmed the
original horizontal/vertical class pairs remain exported. The AMF patch script
also decodes the production asset-hash archive, applies both mappings,
re-encodes it, then decodes the generated file again and asserts the hashes.

## Turbo Combine crop-priority selection

`GameMode.GMCombineAll` previously called the shared seed-limited selector when
the farm contained more plot objects than available seed packages. That helper
walked the world's object array and stopped at the seed count, so a large farm
could omit grown crops from the combine selection based on object order. The
omitted crops appeared as an unselected square or partial block even though
the server accepted every plot the client submitted.

The patched selector partitions eligible plots into harvestable crops and
replantable plots. It includes every harvestable crop first, then uses the
remaining seed capacity for replantable plots. The existing resource check
still handles the explicit out-of-seeds flow when the grown-crop count itself
exceeds the available seed packages.

The maintained FFDec import source is
`fv-decompiled-swf/patches/import-scripts/GameMode/GMCombineAll.as`. The SWF
was imported from `FarmGame-10-inventorysync1.swf` with JPEXS/FFDec 26.2.1,
re-exported for verification, and released as
`FarmGame-10-combineallcroppriority1.swf` with SHA-256
`F1F10B520CBDADA2A07294308C0381A6B82406699A53BC4A6713DD56AFE65877`.

## Pet runaway, rescue, and level-five tricks

`WorldService.performAction` now handles `runaway`, `rescuePet`, and
`performTrick` against the saved pet in the active world. A coin puppy can
run away only after the client-equivalent missed-day threshold. Rescue uses
the catalog's `pet_rescue` price (2 Farm Cash), charges once, and atomically
updates the persisted pet; a rejected rescue returns no `lastFedTime`.

The level-five fetch trick gives one `consume_kibble` in the Giftbox at most
once per pet per 24 hours. This is a conservative restoration reward: the
archived client specifies the fetch response but does not include the original
server's reward pool. The harvest trick acknowledges valid animal targets
and enforces the same cooldown; its actual coin/XP yields remain on each
animal's existing harvest transaction, so the trick reply never grants a
second yield.

The archived pet settings refer to four `consume_harvest_*` definitions that
are absent from this catalog. `PetTrickHarvest` now uses the existing Farm
Hands `CHarvestAnimals` consumable with the pet's configured filter. That
class now reports IDs for type-specific harvests as well as the first-20
variant. `Pet.onRescue` clears its in-session runaway flag, and
`TRescuePet` restores the client's optimistic cash and runaway state on a
rejected response. The four FFDec import sources are under
`fv-decompiled-swf/patches/import-scripts/`; the new client is
`FarmGame-10-petlifecycle1.swf`, built from `FarmGame-10-petfood1.swf` and
re-exported with FFDec 26.2.1. Existing game windows must reload to receive
the filename-revisioned client. The full Laravel suite and a player-driven
pet lifecycle test remain outstanding.
