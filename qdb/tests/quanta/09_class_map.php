<?php
/**
 * doctor rebuilds the class map in place, it never leaves it missing.
 *
 * The map lists the class files of one code tree, and a request only builds it
 * when it is missing (boot.php). Where static/ is shared by processes running
 * different code (the containers of a site during a rolling update), a map
 * deleted there is rebuilt by whichever of them asks first, possibly one of the
 * previous release, and the rest keep that map: every class the new release
 * added is then "not found". So a container starting new code has doctor
 * replace the map with the map of its own code, and a rebuild swaps in a
 * complete file, so a reader never finds the map missing or half written.
 */
require __DIR__ . '/_bootstrap.php';

$quanta = quanta_root();
$host = 'qdbtest-' . bin2hex(random_bytes(4));

// ── a map an older code tree left in the shared static/tmp ───────────────────
// It names every class this checkout ships, except Job, which the older tree
// did not have yet.
$stale = array();
foreach (array('Common', 'Qtags') as $kind) {
    foreach (glob("$quanta/src/modules/*/classes/$kind/*.php") as $file) {
        $stale[$kind][explode('.', basename($file))[0]] = $file;
    }
}
ok(isset($stale['Common']['Job']), 'the fixture starts from a map that names Job');
unset($stale['Common']['Job']);
$map_file = "$quanta/static/tmp/$host/class_map.dat";
mkdir(dirname($map_file), 0777, TRUE);
file_put_contents($map_file, serialize($stale));

list($env) = quanta_env($host);
eq(CLASS_MAP_FILE, $map_file, 'the site reads the planted map');

// ── doctor class_map ─────────────────────────────────────────────────────────
$others = function () use ($map_file) {
    // Less the recipe every doctor run writes.
    return array_values(array_diff(glob(dirname($map_file) . '/*'), array(dirname($map_file) . '/doctor_recipe.txt')));
};
$entries = $others();
$reader = fopen($map_file, 'r');
(new \Quanta\Common\Doctor($env, 'class_map'))->cure();
$rebuilt = unserialize(file_get_contents($map_file));
ok(isset($rebuilt['Common']['Job']), 'doctor class_map rebuilds the map from this code tree');
ok(stream_get_contents($reader, -1, 0) === serialize($stale), 'a reader of the previous map still reads all of it');
fclose($reader);
eq($others(), $entries, 'the rebuild leaves nothing else behind');

// ── doctor check ─────────────────────────────────────────────────────────────
// It used to delete the map, leaving it missing until the next request. It
// also writes the CSS/JS bundles, into a dir the entrypoint creates.
@mkdir(dirname($map_file) . '/files', 0777, TRUE);
(new \Quanta\Common\Doctor($env, 'check'))->cure();
$after_check = is_file($map_file) ? unserialize(file_get_contents($map_file)) : array();
ok(isset($after_check['Common']['Job']), 'doctor check leaves a complete map in place');

finish();
