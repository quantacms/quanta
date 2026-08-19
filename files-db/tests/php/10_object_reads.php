<?php
/**
 * QuantaDb::getObject() — the stdClass reader behind Node::loadJSON().
 *
 * The contract everywhere below: getObject($n) must equal
 * (object) json_decode(getRaw($n)) *by shape*, and the result must be a fresh,
 * unshared, fully mutable object.
 *
 * Compared with var_export(), never json_encode(): a string-keyed PHP array and
 * a stdClass encode to identical JSON, so a round-trip cannot distinguish
 * nested objects from nested arrays — which is the exact bug this reader exists
 * to avoid (a plain `(object) get()` cast only converts the top level).
 */
require __DIR__ . '/_harness.php';

$root = fresh_env();
seed_node($root, 'home', ['title' => 'Home']);

/** Shape parity for one document. */
function parity(string $root, string $name, $data, string $label): void
{
    // Write the JSON verbatim so encoder-specific shapes survive.
    $dir = "$root/home/$name";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $raw = is_string($data) ? $data : json_encode($data);
    file_put_contents("$dir/data.json", $raw);
    QuantaDb::reindex();

    $expected = (object) json_decode($raw);
    $actual = QuantaDb::getObject($name);
    eq(var_export($actual, true), var_export($expected, true), "shape: $label");
}

// --- Shape parity across the JSON type space. --------------------------------
parity($root, 'p-flat', ['a' => 1, 'b' => 'two'], 'flat object');
parity($root, 'p-nested', ['permissions' => ['node_view' => 'anonymous', 'node_edit' => 'admin']],
    'nested object stays an object');
parity($root, 'p-deep', ['a' => ['b' => ['c' => ['d' => 'deep']]]], 'deeply nested objects');
parity($root, 'p-list', ['xs' => [1, 2, 3]], 'nested list stays a list');
parity($root, 'p-objlist', ['xs' => [['k' => 1], ['k' => 2]]], 'list of objects');
parity($root, 'p-empty-obj', '{"o":{}}', 'empty object');
parity($root, 'p-empty-arr', '{"a":[]}', 'empty array');
parity($root, 'p-scalars', '{"n":null,"t":true,"f":false}', 'null/true/false');
parity($root, 'p-int', '{"i":' . PHP_INT_MAX . '}', 'PHP_INT_MAX stays int');
parity($root, 'p-bigint', '{"i":' . PHP_INT_MAX . '0}', 'int overflow becomes float');
parity($root, 'p-floats', '{"a":-0.0,"b":1.5e3,"c":1e-7}', 'float forms');
parity($root, 'p-unicode', '{"s":"café 😀","b":"back\\\\slash"}', 'unicode + escapes');
parity($root, 'p-numkeys', '{"0":"zero","12":"twelve","-3":"neg"}', 'numeric-string keys stay strings');
parity($root, 'p-emptykey', '{"":"empty key"}', 'empty-string key');
parity($root, 'p-dupkeys', '{"k":1,"k":2}', 'duplicate keys: last wins');
parity($root, 'p-mixed', [
    'title' => 'T', 'weight' => 0, 'permissions' => ['a' => 'b'],
    'files' => [['name' => 'x.png', 'size' => 3]],
], 'realistic node document');

// A JSON document whose root is not an object: json_decode gives a list, and
// (object) casts it to a stdClass with numeric property names.
parity($root, 'p-rootarr', '[1,2,3]', 'non-object root');

// --- Absence and corruption. -------------------------------------------------
eq(QuantaDb::getObject('no-such-node'), null, 'absent node -> null');
eq(QuantaDb::getObject('p-flat', 'de'), null, 'absent language -> null');

mkdir("$root/home/p-bad", 0777, true);
file_put_contents("$root/home/p-bad/data.json", '{nope');
QuantaDb::reindex();
throws(fn() => QuantaDb::getObject('p-bad'), QuantaDbException::CORRUPT_JSON,
    'corrupt document throws CORRUPT_JSON');
// getRaw() reports bytes instead of decoding, so a corrupt document is data
// rather than an error — and it now reports the REAL bytes in both modes.
// Its contract is byte fidelity, which shared memory stopped being able to
// honour when the raw JSON moved out of the segment (layout v3), so getRaw()
// reads the file. That also retired a long-standing wart: in daemon mode this
// used to answer '' for a corrupt document, because the daemon keeps no bytes
// for one, which made the escape hatch for inspecting a broken document the
// one call that could not show it to you.
eq(QuantaDb::getRaw('p-bad'), '{nope',
    'getRaw does not throw on a corrupt document');

// --- Mutability: the whole reason this returns an object. --------------------
$o = QuantaDb::getObject('p-mixed');
ok($o instanceof stdClass, 'returns a stdClass');

$o->title = 'changed';                                  // scalar write
$o->permissions->a = 'overwritten';                     // nested object write
$o->permissions->added = 'new';                         // nested property add
$o->files[] = ['name' => 'y.png', 'size' => 9];         // nested list append
$o->attempts[] = 'first';                               // append to a new property
unset($o->weight);                                      // unset (Node::removeAttributeJSON)
$o->fresh = new stdClass();
$o->fresh->deep = 1;                                    // access.hook.inc pattern

eq($o->title, 'changed', 'scalar write');
eq($o->permissions->a, 'overwritten', 'nested object write');
eq($o->permissions->added, 'new', 'nested property add');
eq(count($o->files), 2, 'nested list append');
eq($o->attempts, ['first'], 'append to a new property');
ok(!isset($o->weight), 'unset works');
eq($o->fresh->deep, 1, 'assigning a new nested stdClass works');

$keys = [];
foreach ($o as $k => $v) {                              // qtags.hook.inc pattern
    $keys[] = $k;
}
ok(in_array('title', $keys, true) && !in_array('weight', $keys, true), 'foreach reflects edits');

// json_encode round-trip is what saveJSON does.
$rt = json_decode(json_encode($o), true);
eq($rt['permissions']['added'], 'new', 'json_encode round-trip keeps edits');

// Independence: a second read must not observe the first read's mutations.
$b = QuantaDb::getObject('p-mixed');
eq($b->title, 'T', 'second call is not aliased to the first');
ok(isset($b->weight), 'second call unaffected by the first unset');
eq(count($b->files), 1, 'second call unaffected by the first append');

$c = clone $o;
$c->title = 'cloned';
eq($o->title, 'changed', 'clone is independent at the top level');

// --- String correctness: keys and values must hash and compare properly. -----
// A bad hash shows up as a key foreach can see but array_key_exists denies.
$payload = [];
for ($len = 0; $len <= 17; $len++) {
    $payload['k' . $len . str_repeat('x', $len)] = str_repeat("\xc3\xa9", $len) . "v$len";
}
$payload['high'] = "\xc3\xbf\xc3\xa0\xc3\xa9";
parity($root, 'p-hash', $payload, 'high-byte keys and values');

$h = QuantaDb::getObject('p-hash');
$arr = (array) $h;
$missing = [];
foreach ($arr as $k => $v) {
    if (!array_key_exists($k, $arr)) {
        $missing[] = $k;
    }
}
eq($missing, [], 'every visible key is findable by array_key_exists');
eq(count($arr), count($payload), 'no keys lost or collided');
$flip = array_flip(array_keys($arr));
eq(count($flip), count($payload), 'keys survive array_flip');
ok(in_array($h->high, [$h->high], true), 'returned string compares identical to itself');
eq($h->high, $payload['high'], 'high-byte value round-trips');
$byKey = [$h->high => 1];
ok(array_key_exists($payload['high'], $byKey), 'returned string works as an array key');

// --- Language selection. -----------------------------------------------------
mkdir("$root/home/p-lang", 0777, true);
file_put_contents("$root/home/p-lang/data.json", json_encode(['which' => 'neutral']));
file_put_contents("$root/home/p-lang/data_en.json", json_encode(['which' => 'en']));
QuantaDb::reindex();
eq(QuantaDb::getObject('p-lang')->which, 'neutral', 'neutral document');
eq(QuantaDb::getObject('p-lang', 'en')->which, 'en', 'language document');
eq(QuantaDb::getObject('p-lang', 'de'), null, 'missing language -> null (caller falls back)');

// --- Retained objects survive an index epoch flip. ---------------------------
// Nothing may point into a mapping the extension can replace underneath it.
$retained = QuantaDb::getObject('p-mixed');
QuantaDb::reindex();
QuantaDb::get('p-flat');
QuantaDb::getObject('p-nested');
eq($retained->title, 'T', 'retained object still readable after reindex');
eq($retained->permissions->a, 'b', 'retained nested object still readable');
ok(strlen(json_encode($retained)) > 0, 'retained object still encodable');
ok(preg_match('/T/', $retained->title) === 1, 'retained string survives PCRE');

// --- Many distinct documents read in one epoch. ------------------------------
// Regression: object keys were once memoised by their offset inside the
// document image. Those offsets are image-relative, so different nodes collide
// on the same small offsets and one node silently received another node's
// property names — wrong data, no crash, and invisible unless several distinct
// documents are read between a write and the read that checks it.
$shapes = [];
for ($i = 0; $i < 24; $i++) {
    // Deliberately different key names AND different lengths, so a collision
    // shows up as swapped names rather than coincidentally-equal ones.
    $shapes["k$i"] = [
        "field_$i" . str_repeat('x', $i % 7) => "value-$i",
        'common' => $i,
        "nest$i" => ['inner' => "n$i"],
    ];
    $dir = "$root/home/collide-$i";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents("$dir/data.json", json_encode($shapes["k$i"]));
}
QuantaDb::reindex();

$held = [];
foreach (array_keys($shapes) as $n => $key) {
    $held[$n] = QuantaDb::getObject("collide-$n");
}
$bad = [];
foreach (array_keys($shapes) as $n => $key) {
    $expected = (object) json_decode(json_encode($shapes[$key]));
    if (var_export($held[$n], true) !== var_export($expected, true)) {
        $bad[] = $n;
    }
}
eq($bad, [], 'many documents in one epoch keep their own keys');

// Interleaving reads of different nodes must not disturb an object already
// built — the same failure mode, seen from the other side.
$first = QuantaDb::getObject('collide-0');
for ($i = 1; $i < 24; $i++) {
    QuantaDb::getObject("collide-$i");
}
eq(var_export($first, true), var_export($held[0], true),
    'an object is unaffected by later reads of other nodes');

// --- Image path status is reported. ------------------------------------------
$s = QuantaDb::stats();
ok(in_array($s['image'], ['on', 'off', 'abi-mismatch'], true), 'stats.image reported');
ok(in_array($s['zero_copy'], ['on', 'off'], true), 'stats.zero_copy reported');
if (qdb_daemon_mode() && $s['image'] === 'on') {
    ok($s['img_bytes'] > 0, 'segment carries pre-decoded images');
    ok($s['img_serves'] > 0, 'reads were served from the image');
    eq($s['img_invalid'], 0, 'no image failed validation');
}

finish();
