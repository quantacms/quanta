<?php
/**
 * Read-path assertions that MUST hold identically whether or not the
 * pre-decoded document image is in play.
 *
 * Two operations in this suite have an image fast path and a parse fallback
 * that have to be indistinguishable from outside:
 *
 *   - QuantaDb::load()  — materialises from the image, or from the raw bytes
 *                         via the parse cache when there is no usable image.
 *   - find(['where'…])  — walks the image comparing key bytes, or decodes the
 *                         document into a Value and walks that.
 *
 * Both branches are exercised by requiring this file from two test files:
 * 12_load.php (images on, the default) and 13_image_off.php
 * (quanta_db.image=0). Everything asserted here is a parity claim; facts that
 * are true only of the image path (counters, stats) belong in 12_load.php.
 *
 * Documents are written as raw JSON rather than through json_encode() so the
 * number forms under test ("2.0" vs "2") are the ones the test means.
 */
declare(strict_types=1);

/** Write a document verbatim and make the index see it. */
function put_doc(string $root, string $relpath, string $raw, string $lang = ''): void
{
    $dir = "$root/$relpath";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/data' . ($lang === '' ? '' : "_$lang") . '.json', $raw);
    QuantaDb::reindex();
}

function seed_read_fixture(string $root): void
{
    seed_node($root, 'home', ['title' => 'Home']);

    // --- load() fixtures, under home. ----------------------------------------
    // The realistic node document: nested object, nested list, list of objects.
    put_doc($root, 'home/l-node', '{"title":"N1","weight":3,'
        . '"permissions":{"node_view":"anonymous","node_edit":"admin"},'
        . '"tags":["alpha","beta"],"files":[{"name":"x.png","size":3}]}');
    // Neutral + translation, so language selection has something to select.
    put_doc($root, 'home/l-lang', '{"which":"neutral"}');
    put_doc($root, 'home/l-lang', '{"which":"it"}', 'it');
    // Neutral only: a request for 'it' has to fall back to it, or not, on ask.
    put_doc($root, 'home/l-neutral', '{"which":"neutral"}');
    // A node with no document at all — a legal state (contract §3, deleteDoc).
    if (!is_dir("$root/home/l-empty")) {
        mkdir("$root/home/l-empty", 0777, true);
    }
    // Non-object root: PHP's (object) cast rules have to survive the trip.
    put_doc($root, 'home/l-rootarr', '[1,2,3]');
    // Corrupt neutral, and a corrupt translation over a good neutral: a corrupt
    // document is an ERROR, never an absence to be fallen back from.
    put_doc($root, 'home/l-bad', '{nope');
    put_doc($root, 'home/l-badlang', '{"which":"neutral"}');
    put_doc($root, 'home/l-badlang', '{nope', 'it');

    // --- 'where' corpus, in its own container. -------------------------------
    // Deliberately apart from the corrupt nodes above: a `where` filter reads
    // every candidate, so one corrupt sibling would turn every query here into
    // a CORRUPT_JSON (which is itself asserted, below, on its own container).
    put_doc($root, 'home/w-box/w-1', '{"status":"paid","amount":10,"rate":1.5,'
        . '"flag":true,"opt":null,"note":"caffè 😀",'
        . '"customer":{"country":"IT"},"tags":["a","b"]}');
    put_doc($root, 'home/w-box/w-2', '{"status":"unpaid","amount":20,"rate":2.0,'
        . '"flag":false,"opt":"set","note":"plain",'
        . '"customer":{"country":"DE"},"tags":["b","a"]}');
    // No 'opt', no 'customer': a missing key must never match, not even null.
    put_doc($root, 'home/w-box/w-3', '{"status":"paid","amount":10}');
    // Which document 'where' reads is the 'lang' opt's business.
    put_doc($root, 'home/w-box/w-lang', '{"which":"neutral"}');
    put_doc($root, 'home/w-box/w-lang', '{"which":"it"}', 'it');

    put_doc($root, 'home/w-badbox/w-ok', '{"x":1}');
    put_doc($root, 'home/w-badbox/w-broken', '{nope');
    QuantaDb::reindex();
}

/** QuantaDb::load() — the composed read. */
function assert_load_contract(string $root): void
{
    $path = QuantaDb::path('l-node');

    // --- The whole result shape, once. ---------------------------------------
    $r = QuantaDb::load('l-node');
    ok(is_array($r), 'load returns an array');
    eq(array_keys($r), ['json', 'lang', 'generation', 'path'], 'result keys');
    eq($r['lang'], '', 'neutral document reports lang ""');
    eq($r['path'], $path, 'path reported when "at" was not supplied');
    ok(is_int($r['generation']), 'generation is an int');

    // --- 'as' shapes, against the primitives they must agree with. -----------
    eq(var_export($r['json'], true), var_export(QuantaDb::getObject('l-node'), true),
        'default shape is getObject()');
    $a = QuantaDb::load('l-node', ['as' => 'array']);
    eq(var_export($a['json'], true), var_export(QuantaDb::get('l-node'), true),
        '"array" shape is get()');
    ok($r['json']->permissions instanceof stdClass, 'nested object is an object');
    ok(is_array($a['json']['permissions']), 'nested object is an array in "array" shape');

    // A non-object root goes through PHP's (object) cast rules, which the image
    // path does not serve directly.
    $ra = QuantaDb::load('l-rootarr');
    eq(var_export($ra['json'], true), var_export(QuantaDb::getObject('l-rootarr'), true),
        'non-object root casts like getObject()');

    // Freshly allocated and unshared, like getObject() — $node->json is written
    // to all over Quanta.
    $r['json']->title = 'changed';
    eq(QuantaDb::load('l-node')['json']->title, 'N1', 'result is not aliased');

    // --- 'at': the path check that used to cost a probe and a string. --------
    $at = QuantaDb::load('l-node', ['at' => $path]);
    eq(array_keys($at), ['json', 'lang', 'generation'],
        'no path key when "at" was supplied');
    eq($at['json']->title, 'N1', 'document served under a matching "at"');
    ok(QuantaDb::load('l-node', ['at' => $path . '/']) !== null,
        'trailing slash still names the same directory');
    eq(QuantaDb::load('l-node', ['at' => "$root/home"]), null,
        'name that resolves elsewhere -> null');
    eq(QuantaDb::load('l-node', ['at' => "$root/home/nowhere"]), null,
        'nonexistent "at" -> null');

    // --- Language selection and fallback. ------------------------------------
    eq(QuantaDb::load('l-lang')['json']->which, 'neutral', 'no lang -> neutral');
    $it = QuantaDb::load('l-lang', ['lang' => 'it']);
    eq($it['lang'], 'it', 'lang reports the file that answered');
    eq($it['json']->which, 'it', 'translation wins when it exists');
    $fb = QuantaDb::load('l-neutral', ['lang' => 'it']);
    eq($fb['lang'], '', 'fallback reports the language that actually answered');
    eq($fb['json']->which, 'neutral', 'falls back to the neutral document');
    eq(QuantaDb::load('l-neutral', ['lang' => 'it', 'fallback' => false]), null,
        'fallback=false does not reach the neutral document');
    eq(QuantaDb::load('l-lang', ['lang' => 'de'])['lang'], '',
        'an unknown language falls back like any other miss');

    // --- Absence: three different causes, one answer. ------------------------
    eq(QuantaDb::load('no-such-node'), null, 'absent node -> null');
    eq(QuantaDb::load('l-empty'), null, 'node with no document -> null');
    ok(QuantaDb::path('l-empty') !== null, '...but it still resolves');

    // --- Corruption is an error, not an absence. -----------------------------
    throws(fn() => QuantaDb::load('l-bad'), QuantaDbException::CORRUPT_JSON,
        'corrupt document throws CORRUPT_JSON');
    throws(fn() => QuantaDb::load('l-badlang', ['lang' => 'it']),
        QuantaDbException::CORRUPT_JSON,
        'corrupt translation throws instead of falling back to a good neutral');
    eq(QuantaDb::load('l-badlang')['json']->which, 'neutral',
        'the good neutral document is still readable on its own');

    // --- Argument errors. ----------------------------------------------------
    throws(fn() => QuantaDb::load('l-node', ['bogus' => 1]),
        QuantaDbException::BAD_ARGS, 'unknown opts key');
    throws(fn() => QuantaDb::load('l-node', ['as' => 'bogus']),
        QuantaDbException::BAD_ARGS, 'invalid "as"');
    throws(fn() => QuantaDb::load('l-node', ['lang' => 'it/../x']),
        QuantaDbException::BAD_ARGS, 'invalid language code');
    throws(fn() => QuantaDb::load('a/b'), QuantaDbException::BAD_ARGS,
        'a path is not a node name');

    // --- generation is the one meta() reports, which is what caches key on. --
    // It only advances in daemon mode: in fallback the write generation is a
    // per-process counter that meta() does not see either, so the claim worth
    // asserting everywhere is that the two agree.
    $before = QuantaDb::load('l-node')['generation'];
    QuantaDb::put('l-node', ['title' => 'N1b']);
    $after = QuantaDb::load('l-node');
    eq($after['generation'], QuantaDb::meta('l-node')['generation'],
        'generation agrees with meta()');
    if (qdb_daemon_mode()) {
        ok($after['generation'] > $before, 'generation advances on write');
    }
    eq($after['json']->title, 'N1b', 'load sees the write');
    QuantaDb::putRaw('l-node', '{"title":"N1","weight":3,'
        . '"permissions":{"node_view":"anonymous","node_edit":"admin"},'
        . '"tags":["alpha","beta"],"files":[{"name":"x.png","size":3}]}');
}

/**
 * find(['where' => …]) — equality over every scalar type, and every shape that
 * must NOT match. These are json_value_at()/json_eq()'s semantics, and the
 * image walk has to reproduce them exactly, missing keys and number types
 * included.
 */
function assert_where_contract(string $root): void
{
    $where = fn(array $w) => QuantaDb::find(['father' => 'w-box', 'where' => $w]);

    eq($where(['status' => 'paid']), ['w-1', 'w-3'], 'where: string');
    eq($where(['amount' => 10]), ['w-1', 'w-3'], 'where: int');
    eq($where(['amount' => 10.0]), ['w-1', 'w-3'], 'where: float matches int');
    eq($where(['rate' => 1.5]), ['w-1'], 'where: float');
    eq($where(['rate' => 2]), ['w-2'], 'where: int matches a whole float');
    eq($where(['flag' => true]), ['w-1'], 'where: true');
    eq($where(['flag' => false]), ['w-2'], 'where: false');
    eq($where(['opt' => null]), ['w-1'], 'where: null matches null');
    eq($where(['note' => "caff\u{e8} \u{1f600}"]), ['w-1'], 'where: multibyte string');
    eq($where(['customer.country' => 'IT']), ['w-1'], 'where: dot path');
    eq($where(['tags.0' => 'a']), ['w-1'], 'where: list index');
    eq($where(['tags.1' => 'a']), ['w-2'], 'where: second list index');
    eq($where(['status' => 'paid', 'customer.country' => 'IT']), ['w-1'],
        'where: predicates are AND-ed');

    // Everything that must NOT match — where a hand-rolled comparison usually
    // drifts away from json_decode's.
    eq($where(['amount' => '10']), [], 'where: a string does not equal a number');
    eq($where(['status' => true]), [], 'where: a bool does not equal a string');
    eq($where(['flag' => 1]), [], 'where: 1 does not equal true');
    eq($where(['opt' => null, 'status' => 'unpaid']), [], 'where: AND with a null miss');
    eq($where(['missing' => null]), [], 'where: a missing key never matches null');
    eq($where(['customer' => 'IT']), [], 'where: an object never equals a scalar');
    eq($where(['tags' => 'a']), [], 'where: a list never equals a scalar');
    eq($where(['status.deeper' => 'x']), [], 'where: a path through a scalar');
    eq($where(['tags.9' => 'a']), [], 'where: list index out of range');
    eq($where(['tags.x' => 'a']), [], 'where: non-numeric index into a list');
    eq($where(['customer.0' => 'IT']), [], 'where: numeric key into an object');

    // Ordering and shaping resolve each candidate; the results must not move.
    eq(
        QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']],
            ['order_by' => 'json:amount', 'order' => 'desc']),
        ['w-3', 'w-1'],
        'where + order_by json (ties break on name)'
    );
    eq(
        QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']],
            ['order_by' => 'mtime', 'limit' => 1, 'offset' => 1]),
        ['w-3'],
        'where + order_by mtime + limit/offset'
    );
    $d = QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']],
        ['return' => 'data']);
    eq(array_keys($d), ['w-1', 'w-3'], 'where + return data keys');
    eq($d['w-1']['customer']['country'], 'IT', 'where + return data values');
    $m = QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']],
        ['return' => 'meta']);
    eq(array_keys($m), ['w-1', 'w-3'], 'where + return meta keys');
    eq($m['w-1']['father'], 'w-box', 'where + return meta father');
    eq(QuantaDb::count(['father' => 'w-box', 'where' => ['status' => 'paid']]), 2,
        'count where');

    // The 'lang' opt picks which document 'where' reads.
    eq(QuantaDb::find(['father' => 'w-box', 'where' => ['which' => 'it']],
        ['lang' => 'it']), ['w-lang'], 'where reads the requested language');
    eq(QuantaDb::find(['father' => 'w-box', 'where' => ['which' => 'neutral']]),
        ['w-lang'], 'where reads the neutral document by default');

    // A corrupt candidate is an error from find, exactly as from a direct read.
    throws(fn() => QuantaDb::find(['father' => 'w-badbox', 'where' => ['x' => 1]]),
        QuantaDbException::CORRUPT_JSON,
        'a corrupt candidate document still throws from find');
}
