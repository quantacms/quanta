<?php
/**
 * Worker: become <uid>:<gid>, then write the way a php-fpm worker running as
 * that user does. Prints one JSON object, label => "ok" or the error.
 * Usage: app_user_writes.php <uid> <gid>
 */
$uid = (int) $argv[1];
$gid = (int) $argv[2];
$user = posix_getpwuid($uid);
// Group first: once the uid is not root, the groups can no longer change.
if (!posix_setgid($gid)
    || ($user !== false && !posix_initgroups($user['name'], $gid))
    || !posix_setuid($uid)) {
    fwrite(STDERR, "cannot become $uid:$gid\n");
    exit(2);
}

$out = [];
$try = function (string $label, callable $fn) use (&$out): void {
    try {
        $fn();
        $out[$label] = 'ok';
    } catch (\Throwable $e) {
        $out[$label] = get_class($e) . ': ' . $e->getMessage();
    }
};

// stats() carries the arena's counters only when this process mapped it, and it
// maps it read-write or not at all.
$out['maps the metrics arena'] = isset(QuantaDb::stats()['writes']) ? 'ok' : 'not mapped';
$try('rewrites the node root created', fn() => QuantaDb::put('by-root', ['v' => 2]));
$try('creates a child in the node dir root created', fn() => QuantaDb::put('under-root', ['v' => 1], ['father' => 'by-root']));
$try('creates a node (a new lock file in the lock dir)', fn() => QuantaDb::put('by-app', ['v' => 1], ['father' => 'home']));
$try('writes behind a root-owned lock file', fn() => QuantaDb::put('legacy', ['v' => 2]));
$try('deletes into the trashbin root created', fn() => QuantaDb::delete('doomed-later'));

echo json_encode($out);
