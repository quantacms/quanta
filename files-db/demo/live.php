<?php
/**
 * Live timing endpoint for the quanta_db demo page (index.html).
 *
 * Picks a random real node from the mounted files DB and races the legacy
 * lookup (exec find over the docroot, exactly like Environment::findNodePath)
 * against QuantaDb::path(). Returns JSON consumed by the "live race" section.
 *
 * Serve with PHP's built-in server inside the app container (the main vhost
 * blocks .php files under the site dir by design):
 *
 *   docker compose run --rm -p 8090:8090 --entrypoint php web \
 *     -S 0.0.0.0:8090 -t /var/www/quanta/sites/localhost/quanta/files-db/demo
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 500): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!class_exists('QuantaDb')) {
    fail('quanta_db extension not loaded in this PHP process', 501);
}

$root = rtrim((string) (ini_get('quanta_db.root') ?: getenv('QUANTA_DB_ROOT')), '/');
if ($root === '' || !is_dir($root)) {
    fail('quanta_db.root is not configured or does not exist');
}

// Fresh container => empty /tmp => empty derived index. Rebuild once.
$stats = QuantaDb::stats();
if (($stats['nodes'] ?? 0) === 0) {
    QuantaDb::reindex();
    $stats = QuantaDb::stats();
}

// Candidate nodes come from the index itself (nodes live all over the
// docroot, not just under db/). Only race shell-safe names — the legacy find
// command interpolates the name into the shell string, like the real code.
$candidates = array_values(array_filter(
    QuantaDb::find([], ['return' => 'names']),
    fn(string $n): bool => (bool) preg_match('/^[0-9a-zA-Z][0-9a-zA-Z_-]*$/', $n)
));
if (!$candidates) {
    fail('no nodes indexed under ' . $root);
}
$name = $candidates[array_rand($candidates)];

// Legacy: the exact command shape of Environment::findNodePath().
$cmd = 'find ' . $root . '/ -type d -name "' . $name . '"'
     . ' -not -path */_modules* -not -path *.git*';
$out = [];
$t0 = hrtime(true);
exec($cmd, $out);
$find_us = (hrtime(true) - $t0) / 1000;

// Extension: single indexed lookup (what a cold request pays per name).
$t0 = hrtime(true);
$ext_path = QuantaDb::path($name);
$ext_us = (hrtime(true) - $t0) / 1000;

// And the steady-state cost, averaged.
$t0 = hrtime(true);
for ($i = 0; $i < 200; $i++) {
    QuantaDb::path($name);
}
$ext_warm_us = (hrtime(true) - $t0) / 1000 / 200;

echo json_encode([
    'node' => $name,
    'agree' => $ext_path !== null && ($out[0] ?? '') !== ''
        && realpath($ext_path) === realpath($out[0]),
    'find_us' => round($find_us, 1),
    'ext_us' => round($ext_us, 1),
    'ext_warm_us' => round($ext_warm_us, 2),
    'nodes' => $stats['nodes'] ?? null,
    'links' => $stats['links'] ?? null,
    'root' => $root,
]);
