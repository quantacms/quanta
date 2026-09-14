<?php
/**
 * Worker: hold a node's lock inside an update callback.
 * Touches <ready_file> once the lock is held, then sleeps <hold_ms>.
 * Usage: hold_lock.php <node> <hold_ms> <ready_file>
 */
[, $node, $holdMs, $readyFile] = $argv;
QuantaDb::update($node, function ($cur) use ($holdMs, $readyFile) {
    touch($readyFile);
    usleep((int) $holdMs * 1000);
    return $cur;
});
