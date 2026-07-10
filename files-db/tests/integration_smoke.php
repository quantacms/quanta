<?php
/**
 * In-container integration smoke test for the quanta_db shims, driven through
 * the REAL Quanta classes (Environment, NodeFactory, Node::save).
 *
 * Run inside the app container:
 *   docker compose exec web php /var/www/quanta/sites/localhost/quanta/files-db/tests/integration_smoke.php
 *
 * Creates throwaway nodes under db/qdb-smoke-* and removes them afterwards.
 * The index-row assertions are the discriminators that prove the extension
 * path (not the legacy fallback) actually handled the operation: legacy
 * symlink()/fwrite() would leave no index row / stale generation behind.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '/var/www/quanta/src/modules/environment/classes/Common/DataContainer.class.php';
require_once '/var/www/quanta/src/modules/environment/classes/Common/Environment.class.php';

$_SERVER['HTTPS'] = 1;
$env = new \Quanta\Common\Environment('localhost', NULL, '/var/www/quanta');
require_once '/var/www/quanta/src/autoload.php';
if (!function_exists('t')) {
    function t($string, $replace = array())
    {
        return \Quanta\Common\Localization::t($string, $replace);
    }
}
$env->load();
if (!file_exists(CLASS_MAP_FILE)) {
    $env->mapClasses();
}

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo($cond ? '  ok - ' : '  FAIL - ') . $label . "\n";
}

if (!extension_loaded('quanta_db')) {
    echo "quanta_db extension not loaded\n";
    exit(1);
}

$db = $env->dir['db'];
$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$target = "qdb-smoke-target-$suffix";
$container = "qdb-smoke-cat-$suffix";

// Seed legacy-style (plain mkdir + file_put_contents), like legacy writers do.
foreach ([$target, $container] as $n) {
    mkdir("$db/$n", 0755, true);
    file_put_contents("$db/$n/data.json", json_encode(['title' => $n]));
}

try {
    // 1. nodePath resolves fs-seeded nodes (extension self-heal or find fallback).
    $p = $env->nodePath($target);
    ok($p !== false && realpath($p) === realpath("$db/$target"), "nodePath resolves fs-seeded node ($p)");
    ok(QuantaDb::meta($target) !== null, 'node self-healed into the extension index');

    // 2. linkNodes goes through QuantaDb::link: symlink AND index row.
    \Quanta\Common\NodeFactory::linkNodes($env, $target, $container, ['if_exists' => 'ignore']);
    clearstatcache();
    ok(is_link("$db/$container/$target"), 'linkNodes created the symlink');
    ok(QuantaDb::links($target) === [$container], 'linkNodes went through the extension (index row exists)');

    // 3. Node::save() -> saveJSON goes through QuantaDb::put (generation bump).
    // saveJSON writes the CURRENT language document (data_<lang>.json unless
    // the environment language is neutral) — assert against that file.
    $lang = \Quanta\Common\Localization::getLanguage($env);
    $neutral = ($lang == \Quanta\Common\Localization::LANGUAGE_NEUTRAL);
    $doc_file = $neutral ? 'data.json' : "data_$lang.json";
    $g1 = QuantaDb::meta($target)['generation'];
    $node = \Quanta\Common\NodeFactory::load($env, $target);
    $node->setTitle("saved through shim");
    $node->save();
    clearstatcache();
    $onDisk = json_decode((string) file_get_contents("$db/$target/$doc_file"), true);
    ok(($onDisk['title'] ?? '') === 'saved through shim', "Node::save persisted the document ($doc_file)");
    ok(QuantaDb::meta($target)['generation'] > $g1, 'saveJSON went through the extension (generation bumped)');
    ok((QuantaDb::get($target, $neutral ? null : $lang)['title'] ?? '') === 'saved through shim', 'extension read agrees');

    // 4. unlinkNodes goes through QuantaDb::unlink: symlink and index row gone.
    \Quanta\Common\NodeFactory::unlinkNodes($env, $target, $container, ['if_not_exists' => 'ignore']);
    clearstatcache();
    ok(!is_link("$db/$container/$target"), 'unlinkNodes removed the symlink');
    ok(QuantaDb::links($target) === [], 'unlinkNodes went through the extension (index row gone)');

    // 5. QuantaDb::relink primitive (used by BookingFactory::changeBookingStatus).
    $cat2 = "qdb-smoke-cat2-$suffix";
    mkdir("$db/$cat2", 0755, true);
    file_put_contents("$db/$cat2/data.json", json_encode(['title' => $cat2]));
    QuantaDb::link($target, $container);
    QuantaDb::relink($target, $container, $cat2);
    clearstatcache();
    ok(!file_exists("$db/$container/$target") && is_link("$db/$cat2/$target"), 'relink moved membership atomically');
} finally {
    // Cleanup: remove throwaway nodes from disk and index.
    foreach ([$target, $container, "qdb-smoke-cat2-$suffix"] as $n) {
        if (QuantaDb::exists($n)) {
            QuantaDb::delete($n);
        }
    }
}

echo($fail ? "FAILED ($fail)" : 'PASSED') . " - $pass ok, $fail failed\n";
exit($fail ? 1 : 0);
