<?php
/**
 * Legacy Quanta files-DB logic vs the quanta_db extension.
 *
 * Part 1 — parity tests: the legacy implementation (tests/bench/legacy.php,
 * a faithful copy of Environment::nodePath / saveJSON / linkNodes / DirList
 * patterns) and the extension must give the same answers on the same tree.
 *
 * Part 2 — benchmark: identical operations timed on both, one seeded tree,
 * files as the shared source of truth.
 *
 *   php -n -d extension=quanta_db.so tests/bench/bench.php
 *
 * Sizing knobs (env): QDB_BENCH_N (nodes per father, default 400),
 * QDB_BENCH_COLD (80), QDB_BENCH_WRITES (200), QDB_BENCH_REPEAT (5000).
 */
require __DIR__ . '/../php/_harness.php';
require __DIR__ . '/legacy.php';

$N      = (int) (getenv('QDB_BENCH_N') ?: 400);
$COLD   = (int) (getenv('QDB_BENCH_COLD') ?: 80);
$WRITES = (int) (getenv('QDB_BENCH_WRITES') ?: 200);
$REPEAT = (int) (getenv('QDB_BENCH_REPEAT') ?: 5000);

/* ── Seed one tree, shared by both implementations ─────────────────────── */

$root = fresh_env();
$base = $GLOBALS['__qdb_base'];
$legacy = new LegacyDb($root, "$base/legacy-tmp");

$bookingDoc = static fn(int $i): array => [
    'title' => "Booking $i",
    'status' => $i % 3 === 0 ? 'confirmed' : 'pending',
    'amount' => 100 + ($i % 50),
    'currency' => 'EUR',
    'customer' => ['name' => "Customer $i", 'email' => "c$i@example.com", 'phone' => '+39000000000'],
    'date' => sprintf('2026-07-%02d', ($i % 28) + 1),
    'persons' => ($i % 6) + 1,
    'notes' => str_repeat('Lorem ipsum dolor sit amet. ', 8),
    'source' => 'bench',
];

seed_node($root, 'home', ['title' => 'Home']);
seed_node($root, 'home/businesses', ['title' => 'Businesses']);
seed_node($root, 'home/bookings', ['title' => 'Bookings']);
for ($i = 1; $i <= $N; $i++) {
    seed_node($root, sprintf('home/businesses/biz-%04d', $i), [
        'title' => "Business $i",
        'status' => $i % 4 === 0 ? 'inactive' : 'active',
        'rating' => ($i % 50) / 10,
    ]);
    seed_node($root, sprintf('home/bookings/bk-%04d', $i), $bookingDoc($i));
}
// Containers for the relink/link benches — one pair per implementation.
foreach (['lg-zone', 'xt-zone', 'lg-st-a', 'lg-st-b', 'xt-st-a', 'xt-st-b'] as $c) {
    seed_node($root, "home/$c", []);
}

// Three document shapes for the Node::loadJSON bench (part 3). "tiny" is a
// container node, "typical" is $bookingDoc, "fat" carries a body big enough
// that copying it — rather than parsing it — dominates.
seed_node($root, 'home/doc-tiny', ['title' => 'Tiny', 'status' => 'published', 'weight' => 3]);
seed_node($root, 'home/doc-typical', $bookingDoc(1));
seed_node($root, 'home/doc-fat', $bookingDoc(2) + [
    'body' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 3600),
]);

// The deployed steady state for the extension: index built once.
QuantaDb::reindex();

/* ── Part 1: parity — old logic and extension agree on the same tree ───── */

echo "== parity: legacy logic vs extension\n";

eq($legacy->nodePath('bk-0001'), QuantaDb::path('bk-0001'), 'nodePath == QuantaDb::path');
eq($legacy->nodePath('no-such-node'), false, 'legacy missing node -> false');
eq(QuantaDb::path('no-such-node'), null, 'ext missing node -> null');
eq($legacy->get('bk-0007'), QuantaDb::get('bk-0007'), 'get: identical decoded document');
eq($legacy->get('no-such-node'), null, 'legacy get missing -> null');

eq($legacy->children('businesses'), QuantaDb::children('businesses'), 'children: identical listing');
eq($legacy->children('no-such-node'), QuantaDb::children('no-such-node'), 'children of missing node');

$lf = $legacy->findWhere('bookings', 'status', 'confirmed');
$xf = QuantaDb::find(['father' => 'bookings', 'where' => ['status' => 'confirmed']]);
sort($lf);
sort($xf);
eq($lf, $xf, 'find where status=confirmed: identical result set');
ok(count($lf) > 0, 'find matched something');
eq(
    $legacy->countWhere('bookings', 'status', 'pending'),
    QuantaDb::count(['father' => 'bookings', 'where' => ['status' => 'pending']]),
    'count where: identical'
);

// Writes made by one implementation are visible to the other (files are
// the source of truth; the extension detects the external change).
$legacy->save('bk-0002', ['title' => 'Legacy rewrote this', 'status' => 'confirmed']);
clearstatcache();
// Out-of-band write: synchronous in fallback mode, but in daemon mode it
// reaches the index via inotify, so it gets the contract's staleness budget
// like every other external mutation in the conformance suite.
eq_eventually(fn() => QuantaDb::get('bk-0002')['title'], 'Legacy rewrote this', 'ext sees legacy write');
QuantaDb::put('bk-0003', ['title' => 'Ext rewrote this', 'status' => 'pending']);
clearstatcache();
eq($legacy->get('bk-0003')['title'], 'Ext rewrote this', 'legacy sees ext write');

// Link lifecycle parity, each impl in its own containers.
$legacy->link('bk-0004', 'lg-st-a');
$legacy->statusChange('bk-0004', 'lg-st-a', 'lg-st-b');
clearstatcache();
ok(!is_link("$root/home/lg-st-a/bk-0004") && is_link("$root/home/lg-st-b/bk-0004"), 'legacy status change moved link');
QuantaDb::link('bk-0005', 'xt-st-a');
QuantaDb::relink('bk-0005', 'xt-st-a', 'xt-st-b');
clearstatcache();
ok(!is_link("$root/home/xt-st-a/bk-0005") && is_link("$root/home/xt-st-b/bk-0005"), 'ext relink moved link');
$legacy->unlinkNode('bk-0004', 'lg-st-b');
QuantaDb::unlink('bk-0005', 'xt-st-b');

/* ── Part 2: benchmark ─────────────────────────────────────────────────── */

function ms(callable $fn): float
{
    $t = hrtime(true);
    $fn();
    return (hrtime(true) - $t) / 1e6;
}

$rows = [];
function bench(string $label, int $n, callable $legacyFn, callable $extFn): void
{
    global $rows;
    $rows[] = [$label, $n, ms($legacyFn), ms($extFn)];
}

$coldNames = [];
for ($i = 0; $i < $COLD; $i++) {
    $coldNames[] = sprintf('bk-%04d', ($i * 5) % $N + 1);
}

echo "\n== benchmark (tree: " . (2 * $N + 9) . " nodes; find-scan surface = this tree only,\n";
echo "   production docroot is far larger, so legacy cold lookups are flattered here)\n\n";

// 1. Cold path resolution: legacy pays exec(find) per name; extension its index.
bench("path: cold lookup x$COLD", $COLD,
    function () use ($legacy, $coldNames) {
        $legacy->clearPathCache();
        foreach ($coldNames as $n) {
            $legacy->nodePath($n);
        }
    },
    function () use ($coldNames) {
        foreach ($coldNames as $n) {
            QuantaDb::path($n);
        }
    }
);

// 2. Warm path resolution across simulated requests: legacy readlink()s its
//    tmp symlink cache each new request; extension hits its index.
$warmRounds = 10;
bench('path: warm x' . ($COLD * $warmRounds), $COLD * $warmRounds,
    function () use ($legacy, $coldNames, $warmRounds) {
        for ($r = 0; $r < $warmRounds; $r++) {
            $legacy->newRequest();
            foreach ($coldNames as $n) {
                $legacy->nodePath($n);
            }
        }
    },
    function () use ($coldNames, $warmRounds) {
        for ($r = 0; $r < $warmRounds; $r++) {
            foreach ($coldNames as $n) {
                QuantaDb::path($n);
            }
        }
    }
);

// 3. Reading distinct documents (steady state: symlink path cache warm,
//    one simulated request per round).
$getRounds = 5;
bench('get: distinct docs x' . ($COLD * $getRounds), $COLD * $getRounds,
    function () use ($legacy, $coldNames, $getRounds) {
        for ($r = 0; $r < $getRounds; $r++) {
            $legacy->newRequest();
            foreach ($coldNames as $n) {
                $legacy->get($n);
            }
        }
    },
    function () use ($coldNames, $getRounds) {
        for ($r = 0; $r < $getRounds; $r++) {
            foreach ($coldNames as $n) {
                QuantaDb::get($n);
            }
        }
    }
);

// 4. Re-reading one hot document (extension's object cache vs re-decode).
bench("get: same doc x$REPEAT", $REPEAT,
    function () use ($legacy, $REPEAT) {
        for ($i = 0; $i < $REPEAT; $i++) {
            $legacy->get('bk-0010');
        }
    },
    function () use ($REPEAT) {
        for ($i = 0; $i < $REPEAT; $i++) {
            QuantaDb::get('bk-0010');
        }
    }
);

// 5. Rewriting one document. NOT equivalent guarantees: legacy fopen('w+')
//    is unlocked and non-atomic (torn reads possible); the extension does
//    tmp file + fsync + rename + index update. Durability costs.
$doc = $bookingDoc(10);
bench("put: rewrite doc x$WRITES", $WRITES,
    function () use ($legacy, $doc, $WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            $legacy->save('bk-0011', $doc + ['i' => $i]);
        }
    },
    function () use ($doc, $WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            QuantaDb::put('bk-0012', $doc + ['i' => $i]);
        }
    }
);

// 6. Read-modify-write. Legacy get+save loses updates under concurrency;
//    QuantaDb::update takes the node lock.
bench("update: RMW x$WRITES", $WRITES,
    function () use ($legacy, $WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            $d = $legacy->get('bk-0013');
            $d['n'] = ($d['n'] ?? 0) + 1;
            $legacy->save('bk-0013', $d);
        }
    },
    function () use ($WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            QuantaDb::update('bk-0014', function ($d) {
                $d['n'] = ($d['n'] ?? 0) + 1;
                return $d;
            });
        }
    }
);

// 7. Creating nodes (disjoint containers so the tree stays comparable).
$creates = min(150, $WRITES);
bench("create: new nodes x$creates", $creates,
    function () use ($legacy, $creates, $doc) {
        for ($i = 0; $i < $creates; $i++) {
            $legacy->create(sprintf('lg-new-%04d', $i), 'lg-zone', $doc);
        }
    },
    function () use ($creates, $doc) {
        for ($i = 0; $i < $creates; $i++) {
            QuantaDb::put(sprintf('xt-new-%04d', $i), $doc, ['father' => 'xt-zone']);
        }
    }
);

// 8-10 measure steady state: warm the legacy symlink path cache for every
// booking once, untimed (in production that happened requests ago; a truly
// cold tree pays row 1's exec-find cost per node, once). Each timed legacy
// iteration is a fresh request: memo empty, symlink cache warm.
$legacy->findWhere('bookings', 'status', 'confirmed');

// 8. Listing a big father.
bench("children: $N entries x100", 100,
    function () use ($legacy) {
        for ($i = 0; $i < 100; $i++) {
            $legacy->newRequest();
            $legacy->children('bookings');
        }
    },
    function () {
        for ($i = 0; $i < 100; $i++) {
            QuantaDb::children('bookings');
        }
    }
);

// 9. Filtered query: legacy loads every child's JSON per query.
bench("find: where over $N x20", 20,
    function () use ($legacy) {
        for ($i = 0; $i < 20; $i++) {
            $legacy->newRequest();
            $legacy->findWhere('bookings', 'status', 'confirmed');
        }
    },
    function () {
        for ($i = 0; $i < 20; $i++) {
            QuantaDb::find(['father' => 'bookings', 'where' => ['status' => 'confirmed']]);
        }
    }
);

// 10. Count with the same filter.
bench("count: where over $N x20", 20,
    function () use ($legacy) {
        for ($i = 0; $i < 20; $i++) {
            $legacy->newRequest();
            $legacy->countWhere('bookings', 'status', 'pending');
        }
    },
    function () {
        for ($i = 0; $i < 20; $i++) {
            QuantaDb::count(['father' => 'bookings', 'where' => ['status' => 'pending']]);
        }
    }
);

// 11. Booking status change. Legacy unlink+link is non-atomic; relink is.
$legacy->link('bk-0020', 'lg-st-a');
QuantaDb::link('bk-0021', 'xt-st-a');
bench("relink: status change x$WRITES", $WRITES,
    function () use ($legacy, $WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            [$from, $to] = $i % 2 ? ['lg-st-b', 'lg-st-a'] : ['lg-st-a', 'lg-st-b'];
            $legacy->statusChange('bk-0020', $from, $to);
        }
    },
    function () use ($WRITES) {
        for ($i = 0; $i < $WRITES; $i++) {
            [$from, $to] = $i % 2 ? ['xt-st-b', 'xt-st-a'] : ['xt-st-a', 'xt-st-b'];
            QuantaDb::relink('bk-0021', $from, $to);
        }
    }
);

/* ── Part 3: Node::loadJSON, variant by variant ────────────────────────── */
/*
 * This is the only bench that mirrors a real Quanta call site verbatim:
 * Node::loadJSON (src/modules/node/classes/Common/Node.class.php). Every
 * variant must produce the same stdClass, so the numbers are directly
 * comparable — and directly comparable to the historical measurement that
 * kept reads on the legacy path (~20us legacy vs ~55-120us for get()).
 *
 *   legacy  two is_file() stats + file_get_contents + json_decode
 *   raw     getRaw() (SHM bytes, no syscall) + native json_decode
 *   array   get()    (contract arrays, serde intermediate) + (object) cast
 *   object  getObject() (pre-decoded image, no parse)
 *
 * The "+touch" rows read every field back afterwards. Property reads are
 * where interned-key quality shows up: zend_std_read_property validates its
 * inline cache by pointer identity against the compile-time interned name,
 * so non-canonical keys silently fall back to a full hash lookup on every
 * access. A read-only microbench cannot see that.
 */

$docProfiles = ['tiny' => 'doc-tiny', 'typical' => 'doc-typical', 'fat' => 'doc-fat'];
$LOADS = (int) (getenv('QDB_BENCH_LOADS') ?: 2000);
$hasObject = method_exists('QuantaDb', 'getObject');

// Verbatim copy of the legacy branch of Node::loadJSON.
$legacyLoad = static function (string $path, string $lang): object {
    if (is_file($path . '/data_' . $lang . '.json')) {
        $jsonpath = $path . '/data_' . $lang . '.json';
    } elseif (is_file($path . '/data.json')) {
        $jsonpath = $path . '/data.json';
    } else {
        return new stdClass;
    }
    return (object) json_decode(file_get_contents($jsonpath));
};

// Quanta reads with the neutral language, which is a named constant on the
// PHP side ("language-neutral") but the empty string to the extension. The
// legacy branch therefore always stats data_language-neutral.json and always
// misses — one of the two syscalls the extension read removes.
$NEUTRAL = 'language-neutral';

$touch = static function (object $o): int {
    $n = 0;
    if (isset($o->title)) { $n += strlen((string) $o->title); }
    if (isset($o->status)) { $n += strlen((string) $o->status); }
    if (isset($o->customer->email)) { $n += strlen((string) $o->customer->email); }
    if (isset($o->body)) { $n += strlen((string) $o->body); }
    foreach ($o as $v) { $n++; }
    return $n;
};

$loadRows = [];
foreach ($docProfiles as $profile => $name) {
    $path = QuantaDb::path($name);
    $variants = [
        'legacy' => static fn() => $legacyLoad($path, $NEUTRAL),
        'raw'    => static fn() => (object) json_decode(QuantaDb::getRaw($name)),
        'array'  => static fn() => (object) QuantaDb::get($name),
    ];
    if ($hasObject) {
        $variants['object'] = static fn() => QuantaDb::getObject($name);
    }

    // Every variant must agree before any of them is timed. Compare with
    // var_export, NOT json_encode: a string-keyed PHP array and a stdClass
    // encode to the same JSON, so a round-trip cannot tell
    // {"a":{"b":1}} decoded to nested objects from one decoded to nested
    // arrays — which is exactly how the 'array' variant differs.
    $reference = null;
    foreach ($variants as $label => $fn) {
        $got = $fn();
        if ($reference === null) {
            $reference = $got;
            continue;
        }
        $same = var_export($got, true) === var_export($reference, true);
        // 'array' is expected to differ on any document with nested structure:
        // (object) get() converts the top level only. Recorded, not asserted.
        if ($label === 'array' && !$same) {
            echo "  note - loadJSON/$profile: (object) get() differs from json_decode "
                . "(nested arrays vs objects) — this is why getObject() exists\n";
            continue;
        }
        ok($same, "loadJSON/$profile: $label matches legacy shape");
    }

    foreach ($variants as $label => $fn) {
        $loadRows[] = ["$profile/$label", $LOADS, ms(static function () use ($fn, $LOADS) {
            for ($i = 0; $i < $LOADS; $i++) { $fn(); }
        })];
        $loadRows[] = ["$profile/$label +touch", $LOADS, ms(static function () use ($fn, $touch, $LOADS) {
            for ($i = 0; $i < $LOADS; $i++) { $touch($fn()); }
        })];
    }
}

/* ── Report ────────────────────────────────────────────────────────────── */

printf("%-32s %8s %12s %12s %12s %12s %9s\n",
    'operation', 'ops', 'legacy ms', 'ext ms', 'legacy/op', 'ext/op', 'speedup');
echo str_repeat('-', 102) . "\n";
foreach ($rows as [$label, $n, $lg, $xt]) {
    printf("%-32s %8d %12.1f %12.1f %10.1fus %10.1fus %8.1fx\n",
        $label, $n, $lg, $xt, $lg * 1000 / $n, $xt * 1000 / $n, $xt > 0 ? $lg / $xt : INF);
}
echo "\nspeedup = legacy time / extension time (>1 means the extension is faster).\n";
echo "Write benches trade speed for guarantees the legacy code lacks:\n";
echo "atomic visibility, fsync durability, per-node locking, index consistency.\n";

echo "\n== Node::loadJSON variants (same stdClass out of every one)\n\n";
printf("%-32s %8s %12s %12s %9s\n", 'variant', 'ops', 'total ms', 'per op', 'vs legacy');
echo str_repeat('-', 78) . "\n";
$legacyBase = [];
foreach ($loadRows as [$label, $n, $t]) {
    // "tiny/legacy" and "tiny/legacy +touch" are the two baselines for "tiny".
    [$profile, $rest] = explode('/', $label, 2);
    $key = $profile . (str_ends_with($rest, '+touch') ? '/+touch' : '');
    if (str_starts_with($rest, 'legacy')) {
        $legacyBase[$key] = $t;
    }
    $base = $legacyBase[$key] ?? 0.0;
    printf("%-32s %8d %12.1f %10.2fus %8s\n", $label, $n, $t, $t * 1000 / $n,
        $base > 0 ? sprintf('%.2fx', $base / max($t, 1e-9)) : '-');
}
if (!$hasObject) {
    echo "\n(QuantaDb::getObject() not present in this build — 'object' rows skipped.)\n";
}
echo "\nQDB_BENCH_LOADS=$LOADS. Sizing: QDB_BENCH_N, QDB_BENCH_COLD, QDB_BENCH_WRITES,\n";
echo "QDB_BENCH_REPEAT. Toggle the pre-decoded image with -d quanta_db.image=0|1\n";
echo "and zero-copy string zvals with -d quanta_db.zero_copy=0|1; results must be\n";
echo "identical either way, only the timings change.\n\n";

finish();
