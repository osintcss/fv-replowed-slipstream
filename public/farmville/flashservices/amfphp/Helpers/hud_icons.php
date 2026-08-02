<?php

/**
 * Returns HUD icon names that can safely be created without per-icon data.
 *
 * FlashGiftSend is deliberately not included here.  TPostInit adds every entry
 * through DynamicIconQueue::addIcon(..., false), but FlashGiftQueuedIcon
 * requires an extraData object containing itemName.  Advertising it here makes
 * the Flash client dereference Boolean.itemName and abort post-init.
 *
 * The Flash client can add flash-gift icons itself via
 * FlashGiftQueuedIcon::conditionallyAddFlashGiftIcon(), which supplies the
 * required itemName from the flashGift parameter.
 *
 * @return array Array of icon name strings
 */
function getHudIcons() {
    return [];
}
