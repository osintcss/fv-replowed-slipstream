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
(such as fruit, grain, vegetable, and flowers). The imported item definition
for internal crop name `aloe` has no subtype, while quest settings call its
client-side category `allAloeVera`; the small `aloe` → `AloeVera` alias handles
that display-name exception without requiring a mapping for every crop.
