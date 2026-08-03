# Quest debugging: blank text, intro loops, and quest progression

## Summary

The FarmVille Flash client was able to open replayable quest introductions,
but their text was initially blank.  After the locale problem was resolved,
the introduction dialogs could loop and completing **Let's go!** did not always
unlock the actual quest objectives.

The final fix preserves the client-native introduction dialog, records its
completion correctly, and makes its child quest eligible to start.

## Symptoms seen during the investigation

- A quest window appeared with its portrait, objective art, and `0/1`, but no
  title, dialogue, or objective text.
- The client sent `FarmQuestService.questManagerStartReplayableQuestChain` for
  quests such as `AstonMartinbubble-01-001` and `ingotbubble-01-001`.
- After text began rendering, clicking **Let's go!** could present the same
  introduction again instead of starting a quest with objectives.
- Flash showed the correct native introduction bubbles, proving the client had
  loaded the correct locale assets.

## What the client expects

FarmVille uses two related quest definitions here:

1. A `*bubble*` quest is a one-task `viewDialog` introduction.  It contains
   numbered locale entries such as `_1`, `_2`, and `_3`; it is not a normal
   objective quest.
2. That introduction names a child `Quest`, for example `hula-01-001`.  The
   child has the normal localized `_Title`, `_Body`, `_Description`, and task
   text entries, and it is the quest that should receive objectives/progress.

When a native introduction closes, the client calls
`FarmQuestService.markViewDialogTaskDone`.  The server must mark the intro
complete and then make the child quest eligible.

## Root cause

There were two separate server-side faults after the locale assets were fixed.

1. **The introduction and its child were split across two AMF updates.**
   `questManagerStartReplayableQuestChain` told Flash that the one-task
   `viewDialog` intro was complete so the client would display its native
   dialogue. The server waited for Flash's later
   `markViewDialogTaskDone` acknowledgement before completing the intro and
   starting its child. The Flash quest manager therefore held a completed
   intro without the active child task that should replace it. This produced
   empty/`0/1` sidebar panels, repeated introductions, and quests that seemed
   to disappear after the dialogue.
2. **Child quests were evaluated as level 1.** `completeQuest()` started child
   quests without passing the player's level, so `startQuestIfEligible()` used
   its default level of `1`. A child with a `level_min` prerequisite silently
   failed even for a high-level player. The captured Pony log demonstrated
   this precisely: `ponybubble-01-001` completed, but the level-5
   `pony-01-001` child was absent from `QuestComponent` for a level-83 player.

An earlier attempt to mark every `viewDialog` response complete in the generic
`QuestComponent` response was also incorrect: the Flash batch dispatcher adds
that component to every AMF response. It made the client queue duplicate
dialogs. Completion must be overridden only for the transaction that starts
the introduction.

## Implemented solution

The fix is in
`public/farmville/flashservices/amfphp/Helpers/quest_helper.php`, with the
start-response override handled by `FarmQuestService` and `FlashService`.

- `hasCompletedQuest()` recognizes both normal and replayable completed quest
  lists, and `startQuestIfEligible()` refuses duplicate replay-chain starts.
- A repeat start request returns a successful no-op plus the current quest
  component instead of an AMF error.  The Flash client otherwise leaves its
  **One moment while the quest is started** modal open on that error.
- `completeQuest()` looks up the player's XP-derived level and passes it when
  evaluating every child quest. Level-gated children can therefore start.
- Starting a one-task `viewDialog` intro now completes that intro and starts
  its eligible child **in the same server transaction**. The response's normal
  `QuestComponent` contains the persisted child; a one-response override adds
  a synthetic completed intro so Flash still shows its native dialogue.
- `markViewDialogTaskDone` is idempotent. Flash sends it after the dialogue;
  once the atomic transition has already happened, the acknowledgement returns
  success without altering or restarting the child quest.
- A Start click on an intro left active by an older deployment repairs it using
  the same atomic transition. No manual database reset is required.
- `ensureAvailableStoryQuest()` can still repair an eligible unfinished child
  when a player has no active quests.

## Locale/asset finding

The initial blank text was not fixed by inventing `_Title` or `_Body` fields
for a bubble intro.  Those fields do not exist for that quest type.  The
verified locale SWF contains numbered bubble dialogue, while normal child
quests contain normal title/body/objective keys.  Use the shipped client and
the matching locale asset; do not substitute debug or patched client SWFs.

## Verification procedure

After changing the server code:

```bash
docker compose up -d --build
```

Then close and reopen the game tab (or hard-reload `/play`) so the Flash client
does not retain a previously queued dialog.

Expected flow:

1. Start a replayable quest chain.
2. The native intro bubbles display text once.
3. Click **Let's go!**.
4. The server has already recorded the intro as complete and made its child
   active; Flash's subsequent `markViewDialogTaskDone` acknowledgement is
   accepted as an idempotent success.
5. The intro disappears from active quests, and its child quest becomes active
   with localized objectives and progress tracking.
6. Starting the same completed intro again is rejected rather than creating a
   loop.

Useful server log commands:

```bash
docker compose exec fv-replowed-slipstream sh -lc 'tail -f storage/logs/farmville.log'
docker compose logs -f fv-replowed-slipstream
```

Look for the start-chain request followed by a single
`markViewDialogTaskDone` request and a successful response.  If a child still
does not appear, capture those request/response log entries plus the quest
name; do not rely solely on the visual dialog.

## Bulk equipment actions and persisted task progress

FarmVille sends single plot actions through `WorldService`, but multi-plot
equipment actions through `EquipmentWorldService`.  The latter originally
updated plots and awarded currency/XP but did not call the quest progress
tracker.  The client could therefore display an optimistic task change that
disappeared after reload.

`EquipmentWorldService` now tracks successful bulk plow, plant, harvest, and
combine actions only after their world-object changes have been persisted.
The plant and harvest tracker helpers accept an optional count so a bulk action
increments the objective by the number of affected plots, not merely one.

`getQuestItemCategories()` reads the generic imported category/subtype data
(such as fruit, grain, vegetable, and flowers). Quest settings also use
`all<ItemName>` values such as `allWheat`; the matcher treats the internal item
key as a normalized category, so this works for named crops without a mapping
for every crop. `aloe` remains an explicit exception because its client-facing
name is `AloeVera`, not `aloe`.

Some harvesting tasks use a habitat category rather than a crop category. The
server maps the stable `petrun` world-object family (including finished Pet
Runs) to `petRunHabitat`, allowing its harvest event to persist progress for
those objectives. The corresponding `livestock` building family maps to
`livestockHabitat` for livestock-pen objectives; `paddock` maps to
`paddockHabitat` for horse-paddock objectives.

### Live counters versus saved counters

The Flash client predicts non-server-synchronised quest actions locally. Its
prediction code replaces every non-sticky task counter on each transaction,
which can make a saved harvest counter briefly show progress and then return to
zero until a reload. The server was already saving the correct value; the UI
was using a different, transient value.

During the Docker image build, `scripts/patch-quest-settings.php` marks only
the actions backed by the PHP tracker (`harvestByCode`, `harvestByCategory`,
`plantCropByCode`, `plantCropByCategory`, `plowPlot`, and `useItemByCode`) with the client’s
misspelled `requireServerReponse="true"` flag. Flash then applies the AMF
`QuestComponent` snapshot returned after the world action, keeping the live
counter and persisted counter aligned. Do not mark an action authoritative
until its server-side tracker has been implemented.

The game page includes a `revision=server-progress-1` query parameter on both
quest-settings URLs. This is intentional: Flash can otherwise reuse a cached
pre-patch `questSettings_0.xml.gz` after a Docker rebuild, which produces the
misleading combination of a green **Progress!** indicator while the visible
counter remains at `0/50`. Increment this revision whenever the build-time
quest-settings patch changes.

`QuestComponent.progress` must also be sent as an AMF number array, not an
array of numeric-looking strings. The client performs arithmetic using this
state during a world transaction; a string causes ActionScript concatenation
instead. For example, a local prediction of `10000` combined with saved
progress `"2"` displays as `10002/50`. `buildQuestComponent()` therefore casts
every persisted task value with `intval()` before returning it.

### Quest items received from friends

Some objectives say **Ask Friends** and use a `useItemByCode` task, such as
the blankets in `kepler-01-001` (**It might get chilly**). Their item is saved
in the recipient's giftbox, but the giftbox record and quest progress are
separate state. `PresentService.buyAndSend()` now records the recipient's
`useItemByCode` progress immediately after storing the item. This prevents a
temporary client-side counter from reverting to zero when a later quest
response or reload uses the persisted `QuestComponent`.

For an operator-created test grant, use the normal `PresentService` path when
possible. A raw `playermeta.giftbox` edit bypasses service logic and does not
update quest progress on its own.

## Animal pens: Pet Run storage and duplicate animals

Animal pens are `FeatureBuilding` objects, but they inherit Flash's storage
behaviour. When an animal is dragged into a Pet Run, Flash first harvests the
loose animal, then calls `WorldService.performAction("store", ...)` with the
Pet Run's object ID and the animal's item code. It then sends
`setMultipleFeaturedItems` to choose the animal rendered in the pen.

The old server removed the loose animal but placed it in the player's generic
inventory. It did not update the Pet Run's `contents` or preserve the featured
slot. The client only held its local, optimistic version until a reload. This
made animals disappear from the pen and left the server-side inventory able to
place additional copies.

The server now persists the building's `contents` entries in the Flash format
`{ itemCode, numItem }`, removes the loose world object, and stores the
`featuredItems` state in that building's components. `WorldObject` sends these
fields back at the top level required by `FeatureBuilding.loadObject()`. A
placement originating from a positive storage ID now withdraws from that exact
building before placing, instead of treating it as generic inventory. If the
placement fails, the item is restored to the building.

This applies to Pet Runs and other feature-based animal pens using the same
storage protocol. It does not retroactively move animals that were already
stranded in generic inventory before this change; recover those deliberately,
after inspecting the affected user's storage and pen contents.
