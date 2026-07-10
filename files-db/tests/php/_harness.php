<?php
/**
 * Minimal test harness for the quanta_db conformance suite.
 * Each test file runs as its own PHP process (config binds once per process).
 */
declare(strict_types=1);
error_reporting(E_ALL);

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  ok - $label\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  FAIL - $label\n";
    }
}

function eq($actual, $expected, string $label): void
{
    $cond = $actual === $expected;
    if (!$cond) {
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
    ok($cond, $label);
}

function throws(callable $fn, int $code, string $label): void
{
    try {
        $fn();
        ok(false, "$label (no exception thrown)");
    } catch (\Throwable $e) {
        $isQdb = $e instanceof QuantaDbException;
        if (!$isQdb) {
            echo "    exception class: " . get_class($e) . ": " . $e->getMessage() . "\n";
            ok(false, "$label (wrong exception class)");
            return;
        }
        if ($e->getCode() !== $code) {
            echo "    message: " . $e->getMessage() . "\n";
        }
        eq($e->getCode(), $code, $label);
    }
}

function finish(): void
{
    $f = $GLOBALS['__fail'];
    echo ($f ? "FAILED ($f)" : "PASSED") . " - {$GLOBALS['__pass']} ok, $f failed\n";
    exit($f ? 1 : 0);
}

/** Create an isolated data root + derived dirs, bind config to it. */
function fresh_env(): string
{
    $base = sys_get_temp_dir() . '/qdb-' . bin2hex(random_bytes(4));
    mkdir("$base/root", 0777, true);
    ini_set('quanta_db.root', "$base/root");
    ini_set('quanta_db.index_path', "$base/index.sqlite");
    ini_set('quanta_db.lock_dir', "$base/locks");
    ini_set('quanta_db.trashbin_dir', "$base/trash");
    $GLOBALS['__qdb_base'] = $base;
    return "$base/root";
}

/** Seed a node directly on the filesystem (bypassing the API on purpose). */
function seed_node(string $root, string $relpath, array $data = []): void
{
    $dir = "$root/$relpath";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents("$dir/data.json", json_encode($data));
}

function worker_env(array $extra = []): array
{
    $base = $GLOBALS['__qdb_base'];
    return array_merge([
        'PATH' => (string) getenv('PATH'),
        'QUANTA_DB_ROOT' => "$base/root",
        'QUANTA_DB_INDEX_PATH' => "$base/index.sqlite",
        'QUANTA_DB_LOCK_DIR' => "$base/locks",
        'QUANTA_DB_TRASHBIN_DIR' => "$base/trash",
    ], $extra);
}

/** Spawn a worker PHP process with the extension loaded; returns handle. */
function spawn_worker(string $worker, array $args = [], array $extra_env = []): array
{
    $ext = getenv('QDB_EXT') ?: 'quanta_db';
    $cmd = array_merge(
        [PHP_BINARY, '-n', '-d', "extension=$ext", __DIR__ . "/workers/$worker"],
        array_map('strval', $args)
    );
    $proc = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        worker_env($extra_env)
    );
    if ($proc === false) {
        throw new RuntimeException("failed to spawn worker $worker");
    }
    fclose($pipes[0]);
    return ['proc' => $proc, 'pipes' => $pipes];
}

/** Wait for a worker; returns [exit_code, stdout, stderr]. */
function wait_worker(array &$h): array
{
    $out = stream_get_contents($h['pipes'][1]);
    $err = stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    // proc_close() returns -1 once proc_get_status() has reaped the exit
    // code, so capture it from proc_get_status() first.
    while (true) {
        $st = proc_get_status($h['proc']);
        if (!$st['running']) {
            if ($st['exitcode'] !== -1) {
                $h['exitcode'] = $st['exitcode'];
            }
            break;
        }
        usleep(1000);
    }
    $close = proc_close($h['proc']);
    $code = $h['exitcode'] ?? $close;
    return [$code, $out, $err];
}

function worker_running(array &$h): bool
{
    $st = proc_get_status($h['proc']);
    if (!$st['running'] && $st['exitcode'] !== -1) {
        $h['exitcode'] = $st['exitcode'];
    }
    return $st['running'] ?? false;
}

function worker_pid(array $h): int
{
    $st = proc_get_status($h['proc']);
    return (int) $st['pid'];
}
