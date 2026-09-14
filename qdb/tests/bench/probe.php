<?php
/**
 * Scratch probe: isolate the node-document read so bench rows can be checked
 * against the actual work done. Not part of the conformance suite.
 *   QDB_MODE=daemon php -n -d extension=quanta_db.so tests/bench/probe.php
 */
require __DIR__ . '/../php/_harness.php';

$root = fresh_env();
seed_node($root, 'home', ['title' => 'Home']);

$typical = [
    'title' => 'Booking 1', 'status' => 'confirmed', 'amount' => 120, 'currency' => 'EUR',
    'customer' => ['name' => 'Customer 1', 'email' => 'c1@example.com', 'phone' => '+39000000000'],
    'date' => '2026-07-01', 'persons' => 2,
    'notes' => str_repeat('Lorem ipsum dolor sit amet. ', 8), 'source' => 'bench',
];
seed_node($root, 'home/tiny', ['title' => 'Tiny', 'status' => 'published', 'weight' => 3]);
seed_node($root, 'home/typical', $typical);
seed_node($root, 'home/fat', $typical + [
    'body' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 3600),
]);
QuantaDb::reindex();

$s = QuantaDb::stats();
echo "mode={$s['mode']} nodes={$s['nodes']} doc_bytes={$s['doc_bytes']}\n\n";

$N = (int) (getenv('QDB_PROBE_N') ?: 20000);

function bench_op(string $label, int $n, callable $fn): void
{
    $fn(); // warm
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $fn(); }
    printf("  %-34s %8.3f us/op\n", $label, (hrtime(true) - $t) / 1e3 / $n);
}

foreach (['tiny', 'typical', 'fat'] as $name) {
    $path = QuantaDb::path($name);
    $raw = QuantaDb::getRaw($name);
    printf("== %s (doc %d bytes)\n", $name, strlen((string) $raw));

    bench_op('legacy: file_get_contents+json_decode', $N, static function () use ($path) {
        return (object) json_decode(file_get_contents($path . '/data.json'));
    });
    bench_op('legacy: json_decode only', $N, static fn() => (object) json_decode($raw));
    bench_op('ext: getRaw()', $N, static fn() => QuantaDb::getRaw($name));
    bench_op('ext: getRaw()+json_decode', $N, static fn() => (object) json_decode(QuantaDb::getRaw($name)));
    bench_op('ext: get()  [array]', $N, static fn() => QuantaDb::get($name));
    bench_op('ext: (object) get()', $N, static fn() => (object) QuantaDb::get($name));
    if (method_exists('QuantaDb', 'getObject')) {
        bench_op('ext: getObject()  [stdClass]', $N, static fn() => QuantaDb::getObject($name));
    }
    echo "\n";
}

$GLOBALS['__fail'] = 0;
finish();
