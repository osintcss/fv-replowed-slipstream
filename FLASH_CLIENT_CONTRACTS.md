# Flash client contract catalog

This document is the implementation map for the shipped FarmVille Flash
client. Its purpose is to replace symptom-driven fixes with a repeatable
process: identify the client contract, implement its server side, and record a
small reproducible test.

It is deliberately not a decompiler dump. Decompiled ActionScript is an
investigation aid and should remain uncommitted. This file records only the
behaviour that the PHP AMF service must honour.

## Rules for using this catalog

Each entry has one of these confidence levels:

- **Verified** — read directly from the matching shipped SWF and/or confirmed
  by an AMF request/response capture.
- **Implemented** — the server has code intended to meet the verified
  contract; it still needs the listed regression test.
- **Observed** — seen in a server log, but not yet traced through the SWF.
- **Unknown** — a client call exists, but its expected data/state has not yet
  been established. Do not invent a response based only on its name.

When a new problem is reported, first find its client service/action below.
If it is absent, add an **Observed** entry with the exact request from
`storage/logs/farmville.log`, then promote it to **Verified** only after
checking the relevant ActionScript class.

## Universal AMF transport contract

**Verified.** `Engine.Transactions.Transaction.onAmfComplete` passes the
service response's `data` object to a transaction callback. Callback-specific
fields therefore belong inside `data`; outer AMF transport fields such as
`errorType`, `sequenceNumber`, and `worldTime` are not callback payload.

Example:

```php
return [
    'data' => [
        'success' => true,
    ],
];
```

An AMF error response is not interchangeable with a successful no-op. Several
client flows leave a modal open when a repeated action returns an error. Where
the client can legally retry an already-completed action, prefer an idempotent
success with canonical state.

## Startup and asset contracts

### `UserService.initUser`

**Verified/implemented.** `Transactions.TInitUser` reads a substantial player
state payload directly from `data`, including `player`, `userInfo`, `world`,
`attr`, `experiments`, and `flashHotParams`.

The startup payload is high risk: do not add placeholder fields blindly.
Confirm the shape in `TInitUser.onComplete` before changing a field consumed
by Flash.

Regression test: register a clean account, open `/play`, reload once, and
confirm the farm loads without an ActionScript exception.

### `UserService.postInit`

**Verified/implemented.** `Transactions.TPostInit` reads optional feature
state, including `w2wState`, `avatarState`, `hudIcons`,
`fcSlotMachineRewards`, `bestSellers`, and `lotteryData`. Most are guarded by
the client, but present fields must retain the expected type.

Regression test: complete normal startup and inspect the first AMF batch for
unexpected `Method not found` errors that block a visible system.

### Locale SWF

**Verified/implemented.** The client must receive the matching locale SWF for
the loaded FarmGame build. Quest text comes from this asset; a valid HTTP 200
with the wrong SWF is not sufficient. The Docker build maps the locale request
paths used by the preloader to the matching `en_US.swf` asset.

Regression test: start a normal quest and a `*bubble*` intro. Both the intro
bubbles and the objective panel must display text.

Related detailed notes: [QUEST_DEBUGGING.md](QUEST_DEBUGGING.md).

### `flashHotParams.MINIDARTS`

**Verified/implemented.** `MiniDartsManager` JSON-decodes
`Global.flashHotParams["MINIDARTS"]` in a field initializer. Feature-message
prerequisite evaluation can construct that manager during `TPostInit` even if
Mini Darts is not otherwise available. Therefore the key must always contain
valid JSON. The server supplies an expired runtime configuration, which keeps
the unfinished feature inactive without causing the startup `Error #1009`.

Regression test: launch an account that evaluates feature-message
prerequisites and confirm `TPostInit` completes with no stack involving
`MiniDartsManager` or `JSON.decode`.

## World actions and persistent world state

### `WorldService.performAction`

**Verified/implemented for the actions below.** The action name is the first
parameter, the world object is the second, and action options are typically
the first object in the third parameter array. The server must persist the
authoritative world change before returning a quest snapshot that depends on
it.

| Action | Persistent contract | Status / regression test |
| --- | --- | --- |
| `place` | A market placement creates a world object; a placement from the gift box, home inventory, or a positive building-storage ID consumes exactly one existing item and must not create a market purchase. If placement fails, restore the withdrawn item. | Implemented. Place an animal from each source, reload, and verify exactly one copy exists. |
| `harvest` | Update the target object's post-harvest state and award/track only after persistence succeeds. | Implemented for normal world actions. Harvest a crop/animal, reload, and verify state and quest count. |
| `plow`, `clear`, `clearWithered`, `move`, `sell` | Apply the corresponding position/object state change without losing persistent object fields such as `contents`. | Implemented baseline; regression test each action on a stored building and an ordinary plot. |
| `instantGrow` | Advance eligible object state and apply cash cost only when the world update succeeds. | Implemented baseline; regression test on a crop and a feature building. |
| `store` | Remove the loose resource and increment the target building's `contents` using Flash entries shaped as `{ itemCode, numItem }`. Do **not** substitute generic home inventory for a building's own contents. | Implemented. See animal pen contract below. |
| `setMultipleFeaturedItems` | Save a feature building's featured slot map and return it under `data.featuredItems`. | Implemented. Store an animal, reload, and verify the displayed animal remains. |

### World-object serialization

**Verified/implemented.** A world object is not only its visible fields.
Storage/feature objects may require `contents`, `components`, expansion data,
and class-specific top-level state on reload. Preserve fields not owned by a
particular action instead of replacing the object with the small object sent
by Flash.

`app/Models/WorldObject.php` is the canonical boundary between database state
and Flash world objects. New special fields should be stored in `components`
when appropriate, then emitted at the top-level expected by the client.

### Animal pens and Pet Runs

**Verified/implemented.** A Pet Run identifies itself as `FeatureBuilding`
but inherits Flash storage behaviour:

1. Flash harvests the loose animal.
2. Flash calls `performAction("store")` with the pen ID, animal item code, and
   resource ID.
3. Flash calls `performAction("setMultipleFeaturedItems")` with a slot map,
   e.g. `{ "4": { "itemCode": "7iV", "metaHash": "7iV:" } }`.
4. On reload, `FeatureBuilding.loadObject()` expects `contents`,
   `storageMetadata`, and `featuredItems` at the object top level.

The implementation persists `contents` plus featured items in the building's
components and re-emits them at the required top level. Positive storage IDs
withdraw from that exact building before an animal is placed back on the farm.

Regression test: harvest an animal, put it in a Pet Run, reload, remove it
from the Pet Run, reload, then repeat. At every stage verify there is one and
only one animal.

## Quest contracts

The detailed history and implementation notes are in
[QUEST_DEBUGGING.md](QUEST_DEBUGGING.md). This section is the concise contract
index.

| Service/method | Client contract | Status |
| --- | --- | --- |
| `FarmQuestService.questManagerStartReplayableQuestChain` | Starts a replayable intro. A completed repeat must return successful canonical quest state rather than an AMF error. | Implemented. |
| `FarmQuestService.markViewDialogTaskDone` | Acknowledges the intro after its dialogue. It must be idempotent because the server atomically starts the eligible child before Flash sends this acknowledgement. | Implemented. |
| `FarmQuestService.askForQuestItem` | `Transaction.onAmfComplete` passes the AMF response's outer `data` object to `TAskForQuestItem`, which requires that callback object to contain `data` and `ts`. The handler returns `data: { ts, data: { published: true } }` for a local immediate-publish acknowledgement. With no Facebook delivery path, it grants the remaining amount for the exact active `useItemByCode` task to the giftbox and advances that task's saved progress. It never grants an item for an inactive task or another task action. | Implemented. |
| Quest component in action responses | Server-backed action counters must be returned as the authoritative quest component when the client is configured to wait for the server. | Implemented for supported actions. |
| `harvestByCode`, `harvestByCategory`, `plantCropByCode`, `plantCropByCategory`, `plowPlot`, `useItemByCode` | Persist progress against the active quest and cap it at the task requirement. | Implemented. The Docker build marks only these actions as server-backed in quest settings. |
| Bulk equipment actions | `EquipmentWorldService` applies actions to multiple plots; task progress must use the affected count, not one event per request. | Implemented for bulk plow, plant, harvest, and combine. |

Important: crop categories such as `allWheat` are normalized from imported item
data; this avoids one hard-coded mapping per crop. Habitat objectives use
stable building families such as `petRunHabitat`, `livestockHabitat`, and
`paddockHabitat`.

Regression test: activate a three-task quest; make partial progress with both
ordinary and equipment actions; reload after each step; confirm the saved and
live counters agree.

## Gifts and inventory

### `PresentService.buyAndSend`

**Verified/implemented.** For a quest task using `useItemByCode`, giftbox
contents and quest progress are separate server state. A real service-mediated
gift must add the recipient's gift and update the recipient's server-side task
progress in the same flow.

Regression test: send an Ask Friends item to a player with the matching active
quest, reload their game, and confirm both gift availability and saved quest
progress.

Operator warning: editing `playermeta.giftbox` directly bypasses this service
contract. It can make a temporary client counter appear, but it does not by
itself update persisted quest state.

### Home inventory vs. building storage

**Verified/implemented.** These are different sources:

- Gift box: legacy `-1` and current `-6` identifiers.
- Home inventory: `-2`.
- A building's storage: its positive world-object ID.

Never treat all nonzero source IDs as the same inventory. That conflation is
what made stored animals duplicable.

## Feature systems awaiting contract work

These calls have been observed during normal startup or interaction. Their
absence is not automatically a visible bug, but any user-facing feature they
gate needs a contract before implementation.

| Service/method | Status | Next evidence needed |
| --- | --- | --- |
| `CraftingService.onRefreshMarketView` | Observed; basic crafting-cottage persistence exists. | Trace the client callback and Winery open/queue flow, then capture one successful recipe lifecycle. |
| `FriendSetService.getBatchFriendSetData` | Observed; basic response exists. | Decompile callback for required friend-set shape before expanding social features. |
| `LonelyAnimalFriendSetService.getLonelyAnimalFriendSetData` | Observed. | Trace callback and record required state. |
| `UserService.getAskItemFriends` | Verified/implemented. `TGetAskItemFriends` passes its callback result to the MFS dialog, which reads `requestedFriends[friendType]` arrays. Local neighbors are returned as valid friends for every friend type requested by Flash. | Open an item-request dialog with at least one neighbor; both selection tabs must finish loading and show selectable neighbors. |
| `FBRequestService.sendAskItemsRequest` | Verified/implemented. `TSendAskItemsRequest` sends `(itemName, featureName, requestIds, source, expansion, view, serverTime)` after the client social bridge returns IDs. The offline service accepts the request, records a bounded audit entry, and returns a successful acknowledgement. | Select one or more friends and send an item request; the MFS dialog should advance/close and the server log should show `Offline Ask Items request accepted`. |
| `GiftingService.getGiftNameList` | Unknown. | Trace the requesting transaction and expected list format. |
| `PresentService.receiveAllPresents` | Unknown. | Trace client callback; do not return an arbitrary success object. |
| `UserService.incrementActionCount`, `setActionCount`, `resetSystemNotifications`, `updateFeatureFrequencyWithBackoff` | Observed no-op candidates. | Verify whether callback reads data or only needs a successful acknowledgement. |
| `WatchToEarnRewardGrantService.getUserZid` | Verified/implemented: return `data.success` and string `data.zid`. | Startup regression test. |
| `WatchToEarnRewardGrantService.generateDailyTokens` | Verified/implemented: return `data.success` and capitalized `data.Tokens` array. | Startup regression test. |
| World unlock services (`EnglandService`, `GlenService`, etc.) | Observed. | Treat as a world-access system; define authorization, world state, and callback payload once rather than implementing each named service ad hoc. |

## Implementation priority

The audit's raw call count is not the priority. Work in this order:

1. **Startup and persistent normal-play state** — `UserService` acknowledgements,
   item flags/options, feature-frequency state, and any call that can stop
   `TInitUser` or `TPostInit`. This avoids startup crashes and recurring
   `Method not found` noise.
2. **Gifts and Ask Friends** — `PresentService.receivePresent`,
   `PresentService.receiveAllPresents`, and the friend-selection calls. These
   need one durable pending-present model and an AMF callback trace; do not
   replace them with an empty success response.
3. **World object and crafting lifecycle** — the specific `WorldService` and
   `CraftingService` calls required for placing, opening, storing, claiming,
   and reloading. Each implementation must be reload-tested.
4. **Social progression** — Friend Set, neighbor interaction, and gifting
   variants. Implement a whole feature family at a time rather than isolated
   endpoint names.
5. **Optional/event systems** — ZAPI, ads, promotions, breeding events,
   cross-game campaigns, raffles, and old world campaigns. They are numerous
   in the audit but should not displace a normal-play contract.

### First-pass normal-play acknowledgements

**Verified/implemented.** The following transactions are ordinary client
state updates. Their callbacks are either absent or read only the listed
field, so they are safe to implement before larger event/social systems:

| Service/method | Persisted state | Callback contract |
| --- | --- | --- |
| `UserService.incrementActionCount` | Per-flag action count | No callback; returns `data.actionCount` for observability. |
| `UserService.incrementIntervalActionCount` | Per-flag count and interval start time | `data.actionCount`. |
| `UserService.resetSystemNotifications` | Last reset timestamp | No callback; successful `data` acknowledgement. |
| `UserService.updateFeatureFrequencyWithBackoff` | Timestamp and capped increment | No callback; successful `data` acknowledgement. |
| `UserService.saveOptions` | Sound/music/animation settings | No callback; settings are restored in `initUser`. |
| `UserService.setItemFlag` | Item-flag map | No callback; flags are restored in `initUser`. |
| `CraftingService.onMarkCottageHistorySeen` | Per-craft-type acknowledgement | `data.responseCode`. |

Regression test: reload once after changing options or dismissing a cottage
history prompt; verify that the state persists and no `Method not found`
entry is logged for these calls.

## How to add a contract

1. Reproduce the feature using a clean or known test account.
2. Save the precise service, method, and parameters from
   `storage/logs/farmville.log`.
3. Locate the transaction/callback in the matching shipped SWF with JPEXS.
4. Record only fields the callback reads and only state the next load requires.
5. Mark the entry **Verified** and implement it at the correct persistence
   boundary.
6. Add a short reload-oriented regression test. A feature is not restored
   until it survives a reload.

Useful logs:

```bash
docker compose exec fv-replowed-slipstream sh -lc 'tail -f storage/logs/farmville.log'
docker compose logs -f fv-replowed-slipstream
```

For every contract change, keep the implementation isolated by feature family
and document it here before committing. This makes future integration much
less dependent on rediscovering client behaviour from scratch.

## PowerShell Flash reverse-engineering playbook

Use this when a Flash symptom needs an actual client-contract trace. It is
intended to be copied into a PowerShell session from the repository root.
Decompiled ActionScript belongs in a temporary directory and must not be
committed.

### Prerequisites and common variables

Install [JPEXS Free Flash Decompiler](https://github.com/jindrapetrik/jpexs-decompiler)
and point `$ffdec` at its command-line executable. The exact extracted folder
varies by JPEXS package; use the actual `ffdec-cli.exe` path on the machine.

```powershell
$ffdec = 'C:\path\to\ffdec-cli.exe'
$swf = 'public/farmville/embeds/Flash/v855037.855026/FarmGame.855037.855026.swf'

if (-not (Test-Path -LiteralPath $ffdec)) { throw "JPEXS CLI not found: $ffdec" }
if (-not (Test-Path -LiteralPath $swf)) { throw "FarmGame SWF not found: $swf" }
```

If JPEXS was unpacked under the temporary folder, this is a common shape (only
use it if that file exists):

```powershell
$ffdec = Join-Path $env:TEMP 'fv-ffdec-26.2.1\tool\ffdec-cli.exe'
```

### 1. Find the relevant ActionScript class

Do not export the entire SWF first. Its script dump is very large. Query the
class index and select the small group relevant to the reported symptom:

```powershell
$idx = & $ffdec -dumpAS3 $swf
$idx | Where-Object { $_ -match 'QuestManager$|QuestSettingsInit|QuestEvent' } |
    Select-Object -First 40 | Out-String

$idx | Where-Object { $_ -match 'ZQuest' -and $_ -match 'Manager|SettingsInit' } |
    Out-String
```

For example, a world-action quest counter normally involves `TWorldState`,
`TFarmTransaction`, `QuestManager`, `FarmQuestManager`, and
`FarmQuestSettingsInit`.

### 2. Export only the selected classes

`parallelSpeedUp=false` avoids high memory usage when running JPEXS against
the large FarmGame SWF. Each investigation gets a separate temporary folder,
which is safe to overwrite.

```powershell
$out = Join-Path $env:TEMP 'fv-transaction-metadata'
New-Item -ItemType Directory -Path $out -Force | Out-Null

& $ffdec -config parallelSpeedUp=false `
  -selectclass 'Transactions.TFarmTransaction,Engine.Transactions.Transaction,Engine.Managers.TransactionManager,Classes.Quest.FarmQuestManager' `
  -export script $out $swf

rg -n -C 12 'metadata|QuestComponent|questComponent|onAmfComplete' `
  "$out\scripts" -g '*.as'
```

For the complete server-progress path, export the generic and FarmVille quest
managers plus their settings parsers:

```powershell
$out = Join-Path $env:TEMP 'fv-quest-server-progress'
New-Item -ItemType Directory -Path $out -Force | Out-Null

& $ffdec -config parallelSpeedUp=false `
  -selectclass 'ZQuest.Managers.QuestManager,Classes.Quest.FarmQuestManager,Classes.Quest.FarmQuestSettingsInit,ZQuest.Init.QuestSettingsInit' `
  -export script $out $swf

rg -n -C 12 'metadata|QuestComponent|shouldUpdateProgressFromServerResponse|onTransactionComplete|progress|requireServer' `
  "$out\scripts" -g '*.as'
```

For a focused check of a world callback and the QuestComponent classes:

```powershell
$out = Join-Path $env:TEMP 'fv-world-quest-callback'
New-Item -ItemType Directory -Path $out -Force | Out-Null

& $ffdec -config parallelSpeedUp=false `
  -selectclass 'Transactions.TWorldState,ZQuest.Classes.QuestComponent,Classes.Quest.FarmQuestComponent' `
  -export script $out $swf

Get-ChildItem "$out\scripts" -Recurse -Filter '*.as' | ForEach-Object {
    "`n===== $($_.Name) ====="
    Get-Content $_.FullName -Raw
}
```

Some class names differ by client revision. If JPEXS says a selected class is
missing, use the index command above and export the equivalent class it lists.

### 3. Read the server and client sides together

After finding the client callback, read the corresponding PHP builder and the
specific ActionScript methods side by side. For the quest component:

```powershell
Get-Content public/farmville/flashservices/amfphp/Helpers/quest_helper.php |
    Select-Object -Skip 445 -First 55

Get-Content "$env:TEMP\fv-transaction-metadata\scripts\Classes\Quest\FarmQuestManager.as" |
    Select-Object -Skip 335 -First 195

Get-Content "$env:TEMP\fv-transaction-metadata\scripts\Transactions\TFarmTransaction.as" |
    Select-Object -Skip 60 -First 130
```

Line offsets are a convenience, not a contract. If a different SWF revision
changes them, search by method name instead:

```powershell
rg -n -C 10 'shouldUpdateProgressFromServerResponse|shouldDispatchQuestProgress|taskTypesRequiring|requireServer' `
  "$env:TEMP\fv-transaction-metadata\scripts\Classes\Quest\FarmQuestManager.as" `
  "$env:TEMP\fv-quest-server-progress\scripts\Classes\Quest\FarmQuestSettingsInit.as"
```

### 4. Verify the source and Docker delivery path

Many client settings files are patched only during the image build. Inspect
both the patch and the page flashvars before assuming a source file is the
runtime file:

```powershell
Get-Content scripts/patch-quest-settings.php -Raw
Get-Content Dockerfile -Raw
Get-Content docker-compose.yaml -Raw

rg -n -C 5 'questSettings|xml/gz|locale' Dockerfile apache2-config public `
  -g '!*.swf' -g '!*.gz'
```

For quest-progress work, the tracked source archive is deliberately unchanged:
`scripts/patch-quest-settings.php` modifies the copy inside the Docker image.
After changing that script or the quest URL revision, rebuild the stack and
hard-refresh `/play` so Flash receives the new settings URL:

```bash
docker compose up -d --build
```

### 5. Capture the runtime evidence

Keep a log tail open while performing one exact action, then record the
request parameters, response contract, and reload result in this catalog:

```bash
docker compose exec fv-replowed-slipstream sh -lc 'tail -f storage/logs/farmville.log'
docker compose logs -f fv-replowed-slipstream
```

For a quest-counter report, the important evidence is the one world-action
request and the following `QuestProgress` entry. A `Saved ... updates=[...]`
line proves persistence; it does not by itself prove that Flash received the
correct response shape or uncached quest settings.

## Proactive coverage audit

`scripts/audit-flash-contracts.ps1` exports the matching SWF's ActionScript,
collects literal client service/method calls, and compares them with the PHP
handlers under `public/farmville/flashservices/amfphp/Functions`.

Run it from PowerShell on a machine with JPEXS Free Flash Decompiler:

```powershell
.\scripts\audit-flash-contracts.ps1 `
  -FfdecPath 'C:\path\to\ffdec-cli.exe'
```

It writes an untracked report to `storage/app/flash-contract-audit.md`. The
first pass scans the SWF transaction layer (rather than exporting every client
class, which is unnecessarily memory-intensive). Use the report as a backlog:
group missing calls by feature family, trace the callback for the ones relevant
to normal play, then promote the result into this catalog. A missing handler is
a lead, not sufficient evidence to invent its response shape.
