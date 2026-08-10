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
