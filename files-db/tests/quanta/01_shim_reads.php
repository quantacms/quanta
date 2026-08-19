<?php
/**
 * The $env->db() read surface.
 *
 * Every method here must return the same value whichever implementation
 * answers — FilesDbExt off the index, FilesDb off the filesystem. There is no
 * longer a "cannot answer" return to assert instead, so almost nothing in this
 * file is guarded by mode any more; the exceptions are the ok_ext()
 * discriminators, which prove the extension really was the one that replied.
 *
 * The nodes are seeded the legacy way (mkdir + file_put_contents), so this also
 * covers the index self-heal.
 */
require __DIR__ . '/_bootstrap.php';

list($env, $site, $db) = quanta_env();
$fdb = $env->db();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array(
    'title' => 'Acme',
    'status' => 'active',
    'owner' => array('name' => 'Ada', 'country' => 'IT'),
));
seed($db, 'home/businesses/acme', array('title' => 'Acme IT'), 'it');
seed($db, 'home/businesses/_hidden', array('title' => 'Hidden'));
seed($db, 'home/businesses/beta', array('title' => 'Beta', 'status' => 'draft'));
seed($db, 'home/cats', array('title' => 'Cats'));

// Symlink membership. Created here, with the rest of the fixtures, and NOT
// later among the assertions: in fallback mode the extension answers from a
// per-process filesystem walk taken at its first call, so a symlink created
// after that first call is invisible for the life of the process.
symlink("$db/home/businesses/acme", "$db/home/cats/acme");
if (qdb_daemon_mode()) {
    settle(fn() => \QuantaDb::links('acme') === array('cats'));
}

// ── path / exists / resolvesTo ───────────────────────────────────────────────
$acme_path = "$db/home/businesses/acme";
eq(realpath((string) $fdb->path('acme')), realpath($acme_path),
    'path() resolves a legacy-seeded node in every mode (index self-heal)');
eq($fdb->path('no-such-node'), FALSE, 'path() on a missing node is FALSE, never NULL');
eq($fdb->exists('acme'), TRUE, 'exists() is TRUE');
eq($fdb->exists('no-such-node'), FALSE, 'exists() is FALSE, never NULL');

eq($fdb->resolvesTo('acme', $acme_path), TRUE,
    'resolvesTo() confirms the path in every mode');
eq($fdb->resolvesTo('acme', "$db/home/cats"), FALSE,
    'resolvesTo() rejects a name that lives somewhere else');
eq($fdb->resolvesTo('', $acme_path), FALSE, 'resolvesTo() rejects an empty name');
eq($fdb->resolvesTo('acme', ''), FALSE, 'resolvesTo() rejects an empty path');
eq($fdb->resolvesTo('no-such-node', "$db/home/nope"), FALSE,
    'resolvesTo() rejects a node that does not exist');

// ── data / object / raw ──────────────────────────────────────────────────────
// These have legacy bodies inside the shim, so they must answer in every mode.
eq($fdb->data('acme'), array(
    'title' => 'Acme',
    'status' => 'active',
    'owner' => array('name' => 'Ada', 'country' => 'IT'),
), 'data() returns the decoded neutral document');
eq($fdb->data('acme', 'it'), array('title' => 'Acme IT'), 'data() reads a translation');
eq($fdb->data('acme', 'de'), NULL, 'data() does not fall back between languages');
eq($fdb->data('no-such-node'), NULL, 'data() on a missing node is NULL');

$o = $fdb->object('acme');
ok(is_object($o), 'object() returns an object');
eq($o->title, 'Acme', 'object() top-level field');
ok(is_object($o->owner), 'object() keeps NESTED objects as objects, not arrays');
eq($o->owner->country, 'IT', 'object() nested field');
eq($fdb->object('no-such-node'), NULL, 'object() on a missing node is NULL');

$raw = $fdb->raw('acme');
ok(is_string($raw) && json_decode($raw, TRUE) === $fdb->data('acme'),
    'raw() returns bytes that decode to the same document');

// ── value ────────────────────────────────────────────────────────────────────
eq($fdb->value('acme', 'title'), 'Acme', 'value() reads a top-level field');
eq($fdb->value('acme', 'owner.country'), 'IT', 'value() walks a dot path');
eq($fdb->value('acme', 'owner.missing'), NULL, 'value() on a missing leaf is NULL');
eq($fdb->value('acme', 'title.nope'), NULL, 'value() through a scalar is NULL');
eq($fdb->value('no-such-node', 'title'), NULL, 'value() on a missing node is NULL');

// ── langs ────────────────────────────────────────────────────────────────────
$langs = $fdb->langs('acme');
sort($langs);
eq($langs, array('', 'it'), "langs() lists the neutral document as '' plus translations");
eq($fdb->langs('beta'), array(''), 'langs() on a neutral-only node');
eq($fdb->langs('no-such-node'), array(), 'langs() on a missing node is empty');

// ── hasLang: one probe for one language, not a list ──────────────────────────
eq($fdb->hasLang('acme', 'it'), TRUE, 'hasLang() finds a translation');
eq($fdb->hasLang('acme', 'de'), FALSE, 'hasLang() on a language with no document');
eq($fdb->hasLang('acme', ''), TRUE, "hasLang('') asks about the neutral document");
eq($fdb->hasLang('no-such-node', 'it'), FALSE, 'hasLang() on a missing node is FALSE');

// ── at: the caller already holds the directory ───────────────────────────────
$at = array('at' => $acme_path);
eq($fdb->data('acme', NULL, $at), $fdb->data('acme'), "data() with 'at' agrees");
eq($fdb->langs('acme', $at), $fdb->langs('acme'), "langs() with 'at' agrees");
eq($fdb->hasLang('acme', 'it', $at), TRUE, "hasLang() with 'at' agrees");
// A name that does not live at 'at' is answered ABOUT 'at', never about
// wherever the name resolves — this is what NodeFactory::loadFromRealPath
// needs and what the old resolvesTo() guards were protecting.
eq($fdb->langs('acme', array('at' => "$db/home/businesses/beta")), array(''),
    "'at' wins over the name when the two disagree");

// ── children ─────────────────────────────────────────────────────────────────
// Both branches of FilesDb::children() are exercised: DIR_DIRS is expressible
// against the index, DIR_ALL is not and always takes the scan.
$kids = $fdb->children('businesses', array('type' => \Quanta\Common\Environment::DIR_DIRS));
sort($kids);
eq($kids, array('acme', 'beta'), "children() hides '_'-prefixed nodes by default");

$kids_all = $fdb->children('businesses', array(
    'type' => \Quanta\Common\Environment::DIR_DIRS,
    'exclude_dirs' => '',
));
sort($kids_all);
eq($kids_all, array('_hidden', 'acme', 'beta'), "children() includes '_' names when asked");

eq($fdb->children('no-such-node', array('type' => \Quanta\Common\Environment::DIR_DIRS)),
    array(), 'children() of a missing node is empty, not an error');

// ── links (symlink membership) ───────────────────────────────────────────────
eq($fdb->children('cats', array('symlinks' => 'only')), array('acme'),
    'children(symlinks=only) lists symlinked members');
eq($fdb->children('cats', array(
    'type' => \Quanta\Common\Environment::DIR_DIRS,
    'symlinks' => 'no',
)), array(), 'children(DIR_DIRS, symlinks=no) excludes symlinked members');

// symlinks=no WITHOUT a type means "everything that is not a symlink", which
// includes the plain files in the node directory. The index cannot express
// that, so the shim must stay on the scan and still return data.json.
eq($fdb->children('cats', array('symlinks' => 'no')), array('data.json'),
    'children(symlinks=no) with no type keeps returning plain files');

eq($fdb->links('acme'), array('cats'), 'links() names the containers, in every mode');
eq($fdb->links('beta'), array(), 'links() on an unlinked node is empty, never NULL');
eq($fdb->links('acme', array('in' => "$db/home/cats")), array('cats'),
    "links(in:) scopes the sweep — not expressible against the index, so this "
    . 'always takes the filesystem sweep');

// ── child: one probe for one name, not a list ────────────────────────────────
eq($fdb->child('businesses', 'acme'), TRUE, 'child() finds a child');
eq($fdb->child('businesses', '_hidden'), TRUE, "child() sees '_'-prefixed names");
eq($fdb->child('businesses', 'nope'), FALSE, 'child() on a missing child is FALSE');
eq($fdb->child('acme', 'data.json'), FALSE, 'child() is about nodes, not files');

// ── meta ─────────────────────────────────────────────────────────────────────
// 'generation' and 'containers' are extension-only: a generation counter
// belongs to an index, and containers would cost an exec(find) per node on the
// filesystem — ruinous for sitemap.hook.inc, which asks this per page. The keys
// below are the ones both implementations produce.
$meta = $fdb->meta('acme');
eq($meta['father'], 'businesses', 'meta().father');
ok(realpath($meta['path']) === realpath($acme_path), 'meta().path');
ok(is_int($meta['mtime']) && $meta['mtime'] > 0, 'meta().mtime is set');
$mlangs = $meta['langs'];
sort($mlangs);
eq($mlangs, array('', 'it'), 'meta().langs');
eq($fdb->meta('no-such-node'), NULL, 'meta() on a missing node is NULL');

// ── find / count ─────────────────────────────────────────────────────────────
eq($fdb->find(array('father' => 'businesses', 'where' => array('status' => 'active'))),
    array('acme'), 'find() filters on the document');
eq($fdb->find(array('father' => 'businesses', 'where' => array('status' => 'nope'))),
    array(), 'a find() that matches nothing is an empty list, never NULL');
eq($fdb->find(array('father' => 'businesses', 'where' => array('owner.country' => 'IT'))),
    array('acme'), 'find() filters on a dot path');
eq($fdb->find(array('in' => 'cats')), array('acme'), 'find() by container membership');
$named = $fdb->find(array('father' => 'businesses'), array('return' => 'names'));
sort($named);
eq($named, array('_hidden', 'acme', 'beta'),
    "find() does not hide '_'-prefixed names — only children() applies Quanta's "
    . 'DIR_INACTIVE convention (files-db/docs/usage.md §6)');
eq($fdb->find(array('father' => 'businesses'), array('return' => 'data'))['acme']['title'],
    'Acme', "return => 'data' maps name to document");
// 3, not 2: same rule as above.
eq($fdb->count(array('father' => 'businesses')), 3,
    "count() counts the match set, '_' names included");

// ── where: equality, exactly as the contract defines it ──────────────────────
// The same corpus and the same expectations as the extension's own conformance
// suite (files-db/tests/php/_read_cases.php, assert_where_contract) — run here
// through $env->db(), so the filesystem implementation has to reproduce
// json_eq()'s semantics rather than approximate them with PHP's ==.
seed($db, 'home/w-box', array('title' => 'Where box'));
seed($db, 'home/w-box/w-1', array(
    'status' => 'paid', 'amount' => 10, 'rate' => 1.5,
    'flag' => TRUE, 'opt' => NULL, 'note' => "caff\u{e8} \u{1f600}",
    'customer' => array('country' => 'IT'), 'tags' => array('a', 'b'),
));
seed($db, 'home/w-box/w-2', array(
    'status' => 'unpaid', 'amount' => 20, 'rate' => 2.0,
    'flag' => FALSE, 'opt' => 'set', 'note' => 'plain',
    'customer' => array('country' => 'DE'), 'tags' => array('b', 'a'),
));
// No 'opt', no 'customer': a missing key must never match, not even null.
seed($db, 'home/w-box/w-3', array('status' => 'paid', 'amount' => 10));

$where = function (array $w) use ($fdb) {
    return $fdb->find(array('father' => 'w-box', 'where' => $w));
};

eq($where(array('status' => 'paid')), array('w-1', 'w-3'), 'where: string');
eq($where(array('amount' => 10)), array('w-1', 'w-3'), 'where: int');
eq($where(array('amount' => 10.0)), array('w-1', 'w-3'), 'where: float matches int');
eq($where(array('rate' => 1.5)), array('w-1'), 'where: float');
eq($where(array('rate' => 2)), array('w-2'), 'where: int matches a whole float');
eq($where(array('flag' => TRUE)), array('w-1'), 'where: true');
eq($where(array('flag' => FALSE)), array('w-2'), 'where: false');
eq($where(array('opt' => NULL)), array('w-1'), 'where: null matches null');
eq($where(array('note' => "caff\u{e8} \u{1f600}")), array('w-1'), 'where: multibyte string');
eq($where(array('customer.country' => 'IT')), array('w-1'), 'where: dot path');
eq($where(array('tags.0' => 'a')), array('w-1'), 'where: list index');
eq($where(array('tags.1' => 'a')), array('w-2'), 'where: second list index');
eq($where(array('status' => 'paid', 'customer.country' => 'IT')), array('w-1'),
    'where: predicates are AND-ed');

// Everything that must NOT match — where a hand-rolled comparison usually
// drifts away from json_decode's.
eq($where(array('amount' => '10')), array(), 'where: a string does not equal a number');
eq($where(array('status' => TRUE)), array(), 'where: a bool does not equal a string');
eq($where(array('flag' => 1)), array(), 'where: 1 does not equal true');
eq($where(array('opt' => NULL, 'status' => 'unpaid')), array(), 'where: AND with a null miss');
eq($where(array('missing' => NULL)), array(), 'where: a missing key never matches null');
eq($where(array('customer' => 'IT')), array(), 'where: an object never equals a scalar');
eq($where(array('tags' => 'a')), array(), 'where: a list never equals a scalar');
eq($where(array('status.deeper' => 'x')), array(), 'where: a path through a scalar');
eq($where(array('tags.9' => 'a')), array(), 'where: list index out of range');
eq($where(array('tags.x' => 'a')), array(), 'where: non-numeric index into a list');
eq($where(array('customer.0' => 'IT')), array(), 'where: numeric key into an object');

// Ordering and shaping must not move the result set.
eq($fdb->find(array('father' => 'w-box', 'where' => array('status' => 'paid')),
    array('order_by' => 'json:amount', 'order' => 'desc')),
    array('w-3', 'w-1'), 'where + order_by json (ties break on name)');
eq($fdb->find(array('father' => 'w-box', 'where' => array('status' => 'paid')),
    array('order_by' => 'mtime', 'limit' => 1, 'offset' => 1)),
    array('w-3'), 'where + order_by mtime + limit/offset');
eq($fdb->count(array('father' => 'w-box', 'where' => array('status' => 'paid'))), 2,
    'count where');

finish();
