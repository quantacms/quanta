<?php
/** Contract §10 scenarios: children listing, link/unlink/relink, scenario 8. */
require __DIR__ . '/_harness.php';

$root = fresh_env();
seed_node($root, 'home', []);
seed_node($root, 'home/cats', []);
seed_node($root, 'home/items', []);
seed_node($root, 'home/items/item-a', ['t' => 'A']);
seed_node($root, 'home/items/item-a/sub-a', ['t' => 'SubA']);
seed_node($root, 'home/items/item-b', ['t' => 'B']);
seed_node($root, 'home/items/_hidden', []);

// children(): default hides '_'-prefixed (Quanta DIR_INACTIVE convention).
eq(QuantaDb::children('items'), ['item-a', 'item-b'], 'children default');
eq(
    QuantaDb::children('items', ['include_hidden' => true]),
    ['_hidden', 'item-a', 'item-b'],
    'children include_hidden'
);
eq(QuantaDb::children('items', ['type' => 'dirs']), ['item-a', 'item-b'], 'children dirs');
eq(QuantaDb::children('items', ['type' => 'links']), [], 'children links (none yet)');
eq(QuantaDb::children('item-a'), ['sub-a'], 'nested children');
eq(QuantaDb::children('nonexistent'), [], 'children of missing node -> []');
throws(
    fn() => QuantaDb::children('items', ['type' => 'bogus']),
    QuantaDbException::BAD_ARGS,
    'invalid children type'
);

// link(): symlink into container + index row.
ok(QuantaDb::link('item-a', 'cats'), 'link item-a into cats');
ok(is_link("$root/home/cats/item-a"), 'symlink exists on disk');
eq(readlink("$root/home/cats/item-a"), "$root/home/items/item-a", 'symlink targets node dir');
eq(QuantaDb::children('cats', ['type' => 'links']), ['item-a'], 'link listed as child');
eq(QuantaDb::children('cats', ['type' => 'dirs']), [], 'link not listed as dir');
eq(QuantaDb::links('item-a'), ['cats'], 'links() finds container');

// Duplicate link: default ignore, opt-in error.
ok(QuantaDb::link('item-a', 'cats'), 'duplicate link ignored by default');
throws(
    fn() => QuantaDb::link('item-a', 'cats', ['if_exists' => 'error']),
    QuantaDbException::EXISTS,
    'duplicate link with if_exists=error'
);
throws(
    fn() => QuantaDb::link('ghost', 'cats'),
    QuantaDbException::BAD_ARGS,
    'link of missing target'
);

// unlink().
eq(QuantaDb::unlink('item-a', 'cats'), true, 'unlink removes');
clearstatcache(); // fs changes were made by the extension, not by PHP
ok(!is_link("$root/home/cats/item-a"), 'symlink gone');
eq(QuantaDb::links('item-a'), [], 'links() empty after unlink');
eq(QuantaDb::unlink('item-a', 'cats'), false, 'unlink again -> false');
throws(
    fn() => QuantaDb::unlink('item-a', 'cats', ['if_not_exists' => 'error']),
    QuantaDbException::IO,
    'unlink missing with if_not_exists=error'
);

// relink(): the booking status-change primitive (scenario 7's single-process half).
seed_node($root, 'home/st-unpaid', []);
seed_node($root, 'home/st-paid', []);
QuantaDb::link('item-b', 'st-unpaid');
ok(QuantaDb::relink('item-b', 'st-unpaid', 'st-paid'), 'relink moves membership');
clearstatcache();
ok(!file_exists("$root/home/st-unpaid/item-b"), 'gone from old container');
ok(is_link("$root/home/st-paid/item-b"), 'present in new container');
eq(QuantaDb::links('item-b'), ['st-paid'], 'links() reflects relink');

// relink with no existing membership just links into the destination.
ok(QuantaDb::relink('item-a', 'st-unpaid', 'st-paid'), 'relink without source link');
eq(QuantaDb::links('item-a'), ['st-paid'], 'created in destination');

// delete() removes inbound symlinks (scenario 8).
QuantaDb::delete('item-b');
clearstatcache();
ok(!file_exists("$root/home/st-paid/item-b"), 'inbound symlink removed on delete');
eq(QuantaDb::links('item-b'), [], 'links rows removed on delete');
eq(QuantaDb::children('st-paid', ['type' => 'links']), ['item-a'], 'other links untouched');

finish();
