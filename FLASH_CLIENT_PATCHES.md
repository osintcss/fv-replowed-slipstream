# Flash client patches

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
