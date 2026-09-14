<?php
/**
 * The integrity hook's language-collapse repair.
 *
 * integrity_check_node() folds a non-translated node's data_<lang>.json files
 * onto data.json. It is the call site built out of langs() + raw() + putRaw()
 * + deleteDoc(), and every one of those has a filesystem fallback — so the
 * repair has to reach the same on-disk state in all three modes.
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\NodeFactory;

const INTEGRITY_CHECK = '\Quanta\Common\integrity_check_node';

list($env, $site, $db) = quanta_env();

// Environment::load() has already require_once'd every module hook file, so the
// function is here — under the module's namespace, not the global one.
ok(function_exists(INTEGRITY_CHECK), 'the integrity hook is loaded by the Environment');

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/things', array('title' => 'Things'));

// ── A node with a neutral document AND translations: translations go ─────────
seed($db, 'home/things/keeper', array('title' => 'Keeper'));
seed($db, 'home/things/keeper', array('title' => 'Keeper IT'), 'it');
seed($db, 'home/things/keeper', array('title' => 'Keeper DE'), 'de');

$keeper = NodeFactory::load($env, 'keeper');
(INTEGRITY_CHECK)($keeper, $env);
clearstatcache(TRUE);

ok(is_file("$db/home/things/keeper/data.json"), 'the neutral document survived');
ok(!is_file("$db/home/things/keeper/data_it.json"), 'the it translation was dropped');
ok(!is_file("$db/home/things/keeper/data_de.json"), 'the de translation was dropped');
eq($env->db()->data('keeper'), array('title' => 'Keeper'),
    'the surviving document still reads correctly');
eq($env->db()->langs('keeper'), array(''), 'only the neutral language is left');

// ── A node with translations only: the first becomes the neutral document ────
// Byte stability matters here: the survivor is moved with putRaw, which stores
// the bytes verbatim, so a document nobody edited must not come back
// re-escaped by json_encode.
$raw_it = json_encode(array('title' => 'Only IT', 'url' => 'http://a/b', 'city' => 'città'));
$promoted_dir = "$db/home/things/promoted";
mkdir($promoted_dir, 0777, TRUE);
file_put_contents("$promoted_dir/data_it.json", $raw_it);
settle_langs('promoted', array('it'));

$promoted = NodeFactory::load($env, 'promoted');
(INTEGRITY_CHECK)($promoted, $env);
clearstatcache(TRUE);

ok(is_file("$promoted_dir/data.json"), 'a translation-only node gained a neutral document');
ok(!is_file("$promoted_dir/data_it.json"), 'the translation it came from is gone');
eq($env->db()->data('promoted'), array(
    'title' => 'Only IT',
    'url' => 'http://a/b',
    'city' => 'città',
), 'the promoted document kept its content');
eq($env->db()->langs('promoted'), array(''), 'only the neutral language is left');
eq((string) file_get_contents("$promoted_dir/data.json"), $raw_it,
    'the promoted document is byte-identical (putRaw stored it verbatim)');

// ── A node with several translations and no neutral one ──────────────────────
$multi_dir = "$db/home/things/multi";
mkdir($multi_dir, 0777, TRUE);
file_put_contents("$multi_dir/data_de.json", json_encode(array('title' => 'Multi DE')));
file_put_contents("$multi_dir/data_it.json", json_encode(array('title' => 'Multi IT')));
// Both translations, not just the node: the collapse below decides from the
// language list, so a half-seen node quietly leaves one behind.
settle_langs('multi', array('de', 'it'));

$multi = NodeFactory::load($env, 'multi');
(INTEGRITY_CHECK)($multi, $env);
clearstatcache(TRUE);

ok(is_file("$multi_dir/data.json"), 'a multi-translation node gained a neutral document');
eq($env->db()->langs('multi'), array(''), 'every translation was collapsed');
$title = $env->db()->value('multi', 'title');
ok(in_array($title, array('Multi DE', 'Multi IT'), TRUE),
    "the surviving document is one of the translations ($title)");

// ── A node that is already correct is left alone ─────────────────────────────
seed($db, 'home/things/clean', array('title' => 'Clean'));
$before = (string) file_get_contents("$db/home/things/clean/data.json");
$clean = NodeFactory::load($env, 'clean');
(INTEGRITY_CHECK)($clean, $env);
clearstatcache(TRUE);
eq((string) file_get_contents("$db/home/things/clean/data.json"), $before,
    'a node with only a neutral document is untouched, byte for byte');

// ── Running the repair twice is a no-op ──────────────────────────────────────
$again = (string) file_get_contents("$promoted_dir/data.json");
(INTEGRITY_CHECK)(NodeFactory::load($env, 'promoted'), $env);
clearstatcache(TRUE);
eq((string) file_get_contents("$promoted_dir/data.json"), $again,
    'the repair is idempotent');

// ── Called without an Environment it still works, on the legacy path ─────────
// The signature keeps $env optional, so an old caller cannot fatal.
seed($db, 'home/things/legacy', array('title' => 'Legacy'));
seed($db, 'home/things/legacy', array('title' => 'Legacy IT'), 'it');
(INTEGRITY_CHECK)(NodeFactory::load($env, 'legacy'));
clearstatcache(TRUE);
ok(is_file("$db/home/things/legacy/data.json"), 'no-Environment call kept the neutral document');
ok(!is_file("$db/home/things/legacy/data_it.json"), 'no-Environment call dropped the translation');

finish();
