<?php
/**
 * A PHP CLI run as root leaves nothing the site's user cannot write.
 *
 * The extension runs inside php-fpm, as the site's user. A PHP CLI started as
 * root (`kubectl exec <pod> -- php doctor …`, no su) loads the same extension,
 * and what it created came out root-owned 0755/0644: the lock dir and the lock
 * files in it, the metrics arena, a new node's dir and document, the trashbin
 * dirs. The site's user then got EACCES on every write that needed one of them
 * (a lock file it may not open, a lock dir it may not create in), until someone
 * chowned them back by hand.
 *
 * So: root writes into a tree owned by an unprivileged user; everything it
 * creates must belong to that user; and a process running as that user must be
 * able to write afterwards, including behind a lock file that IS root-owned, as
 * one left by an older build is.
 *
 * Needs root. Fallback mode only: in daemon mode the harness starts qdbd as
 * root, which is not what runs in production (supervisord starts it as the
 * site's user) and which creates root-owned files in the derived dir itself.
 */
require __DIR__ . '/_harness.php';

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  skip - needs root\n";
    finish();
}
if (qdb_daemon_mode()) {
    echo "  skip - fallback mode only (the harness would run qdbd as root)\n";
    finish();
}
$app = posix_getpwnam('www-data') ?: posix_getpwnam('nobody');
if ($app === false) {
    echo "  skip - no unprivileged user to own the tree\n";
    finish();
}
$uid = $app['uid'];
$gid = $app['gid'];

/** lchown a whole tree, the way the container entrypoint chowns the site. */
function chown_tree(string $path, int $uid, int $gid): void
{
    lchown($path, $uid);
    lchgrp($path, $gid);
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') {
                chown_tree("$path/$e", $uid, $gid);
            }
        }
    }
}

/** Every entry under $path (itself included) that $uid does not own. */
function not_owned_by(string $path, int $uid): array
{
    clearstatcache();
    $st = @lstat($path);
    if ($st === false) {
        return [];
    }
    $bad = $st['uid'] === $uid ? [] : ["$path (uid {$st['uid']})"];
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') {
                $bad = array_merge($bad, not_owned_by("$path/$e", $uid));
            }
        }
    }
    return $bad;
}

function works(callable $fn, string $label): void
{
    try {
        $fn();
        ok(true, $label);
    } catch (\Throwable $e) {
        echo '    ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        ok(false, $label);
    }
}

$root = fresh_env();
$base = $GLOBALS['__qdb_base'];

// The trashbin on another filesystem when there is one, as in the container,
// where it is on the static volume and the nodes are on the db volume: a delete
// then copies the node instead of renaming it. Its parent belongs to the site's
// user, like static/tmp/<site>; the trashbin itself does not exist yet.
$trash_home = is_dir('/dev/shm') && is_writable('/dev/shm')
    ? '/dev/shm/' . basename($base)
    : "$base/static";
mkdir($trash_home);
$trash = "$trash_home/trashbin";
ini_set('quanta_db.trashbin_dir', $trash);
register_shutdown_function(function () use ($trash_home) {
    exec('rm -rf ' . escapeshellarg($trash_home));
});

seed_node($root, 'home', ['title' => 'Home']);
seed_node($root, 'home/doomed', ['title' => 'Doomed']);
seed_node($root, 'home/doomed-later', ['title' => 'Doomed later']);
seed_node($root, 'home/legacy', ['title' => 'Legacy']);
chown_tree($base, $uid, $gid);
chown_tree($trash_home, $uid, $gid);

// ── root writes ──────────────────────────────────────────────────────────────
// The first call binds the config and maps the metrics arena; the first write
// makes the lock dir.
works(fn() => QuantaDb::put('by-root', ['v' => 1], ['father' => 'home']), 'root creates a node');
works(fn() => QuantaDb::put('home', ['title' => 'Home, again']), 'root rewrites a node');
works(fn() => QuantaDb::delete('doomed'), 'root deletes a node');

eq(not_owned_by($base, $uid), [], "root left nothing in the data root or the derived dir that the site's user does not own");
eq(not_owned_by($trash_home, $uid), [], "root left nothing in the trashbin that the site's user does not own");

// A lock file root created before root CLIs handed them over, the way an older
// build left them.
touch("$base/locks/legacy.lock");
chmod("$base/locks/legacy.lock", 0644);
chown("$base/locks/legacy.lock", 0);
chgrp("$base/locks/legacy.lock", 0);

// ── then the site's user, in a process of its own (php-fpm) ──────────────────
$w = spawn_worker('app_user_writes.php', [$uid, $gid], ['QUANTA_DB_TRASHBIN_DIR' => $trash]);
$out = stream_get_contents($w['pipes'][1]);
$err = stream_get_contents($w['pipes'][2]);
$code = proc_close($w['proc']);
eq($code, 0, "the site user's worker ran");
$results = json_decode($out, true);
if (!is_array($results)) {
    echo '    ' . worker_failure($code, $out, $err) . "\n";
    $results = [];
}
foreach ([
    'maps the metrics arena',
    'rewrites the node root created',
    'creates a child in the node dir root created',
    'creates a node (a new lock file in the lock dir)',
    'writes behind a root-owned lock file',
    'deletes into the trashbin root created',
] as $label) {
    eq($results[$label] ?? 'missing', 'ok', "after root, the site's user $label");
}

finish();
