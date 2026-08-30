<?php

/**
 * Sheep-pen initialization hooks used by the legacy Flash client.
 *
 * The original service granted a one-time bundle of pattern items.  The
 * pattern catalog is not present in this server build, but the client still
 * calls this endpoint when the pen is opened.  Returning the expected empty
 * gift list is important: it lets the client mark the initialization as
 * complete instead of leaving a failed transaction in the breeding queue.
 */
class SheepPenService
{
    public static function grantFreePatterns($playerObj, $request, $market = null): array
    {
        return ['data' => ['gifts' => []]];
    }
}
