<?php
/**
 * Minimal test harness for the quanta_db conformance suite.
 * Each test file runs as its own PHP process (config binds once per process).
 *
 * Modes (env QDB_MODE):
 *   fallback (default) — no daemon; the extension serves from its per-process
 *                        walk snapshot + direct file reads.
 *   daemon             — fresh_env() spawns a qdbd for the test root and waits
 *                        for coherence; every read is served from shared memory.
 */
declare(strict_types=1);
error_reporting(E_ALL);

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__qdbd'] = null;

function qdb_daemon_mode(): bool
{
    return getenv('QDB_MODE') === 'daemon';
}

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

/**
 * Out-of-band filesystem changes reach the daemon via inotify — asynchronously.
 * These variants poll (daemon mode only; fallback stays a single immediate
 * check) until the assertion holds or the contract's staleness budget is spent.
 */
function qdb_settle(callable $cond, float $budget = 2.0): bool
{
    $deadline = microtime(true) + (qdb_daemon_mode() ? $budget : 0.0);
    while (true) {
        if ($cond()) {
            return true;
        }
        if (microtime(true) >= $deadline) {
            return false;
        }
        usleep(20_000);
    }
}

function eq_eventually(callable $fn, $expected, string $label): void
{
    qdb_settle(fn() => $fn() === $expected);
    eq($fn(), $expected, $label);
}

function ok_eventually(callable $fn, string $label): void
{
    qdb_settle(fn() => (bool) $fn());
    ok((bool) $fn(), $label);
}

function throws_eventually(callable $fn, int $code, string $label): void
{
    qdb_settle(function () use ($fn, $code) {
        try {
            $fn();
            return false;
        } catch (\Throwable $e) {
            return $e instanceof QuantaDbException && $e->getCode() === $code;
        }
    });
    throws($fn, $code, $label);
}

function finish(): void
{
    qdb_daemon_stop();
    $f = $GLOBALS['__fail'];
    echo ($f ? "FAILED ($f)" : "PASSED") . " - {$GLOBALS['__pass']} ok, $f failed\n";
    exit($f ? 1 : 0);
}

/**
 * Create an isolated data root + derived dirs, bind config to it.
 * In daemon mode also spawns a qdbd for that root ($daemon_env lets a test
 * tune it, e.g. QUANTA_DB_SHM_SIZE_MB for the compaction test).
 */
function fresh_env(array $daemon_env = []): string
{
    $base = sys_get_temp_dir() . '/qdb-' . bin2hex(random_bytes(4));
    mkdir("$base/root", 0777, true);
    ini_set('quanta_db.root', "$base/root");
    ini_set('quanta_db.shm_dir', "$base/shm");
    ini_set('quanta_db.socket_path', "$base/qdbd.sock");
    ini_set('quanta_db.metrics_path', "$base/metrics.shm");
    ini_set('quanta_db.lock_dir', "$base/locks");
    ini_set('quanta_db.trashbin_dir', "$base/trash");
    $GLOBALS['__qdb_base'] = $base;
    if (qdb_daemon_mode()) {
        qdb_daemon_start($daemon_env);
    }
    return "$base/root";
}

/** Environment a qdbd or worker needs to bind the same derived paths. */
function qdb_env(array $extra = []): array
{
    $base = $GLOBALS['__qdb_base'];
    return array_merge([
        'PATH' => (string) getenv('PATH'),
        'QDB_MODE' => (string) getenv('QDB_MODE'),
        'QUANTA_DB_ROOT' => "$base/root",
        'QUANTA_DB_SHM_DIR' => "$base/shm",
        'QUANTA_DB_SOCKET_PATH' => "$base/qdbd.sock",
        'QUANTA_DB_METRICS_PATH' => "$base/metrics.shm",
        'QUANTA_DB_LOCK_DIR' => "$base/locks",
        'QUANTA_DB_TRASHBIN_DIR' => "$base/trash",
    ], $extra);
}

/** Spawn qdbd for the current env and wait until it publishes coherence. */
function qdb_daemon_start(array $extra_env = []): void
{
    $bin = getenv('QDBD_BIN') ?: 'qdbd';
    $proc = proc_open(
        [$bin],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        qdb_env($extra_env)
    );
    if ($proc === false) {
        fwrite(STDERR, "cannot spawn qdbd ($bin)\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $GLOBALS['__qdbd'] = ['proc' => $proc, 'pipes' => $pipes];
    register_shutdown_function('qdb_daemon_stop');
    $deadline = microtime(true) + 10.0;
    while (!QuantaDb::coherent()) {
        if (microtime(true) >= $deadline) {
            $err = stream_get_contents($pipes[2]) ?: '';
            fwrite(STDERR, "qdbd did not become coherent in 10s\n$err\n");
            exit(1);
        }
        usleep(20_000);
    }
}

/** Stop the daemon (default SIGTERM; pass SIGKILL for the crash tests). */
function qdb_daemon_stop(int $signal = 15): void
{
    $h = $GLOBALS['__qdbd'];
    if ($h === null) {
        return;
    }
    $GLOBALS['__qdbd'] = null;
    @proc_terminate($h['proc'], $signal);
    // Reap so kill -9 tests can immediately restart on the same socket.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $st = proc_get_status($h['proc']);
        if (!$st['running']) {
            break;
        }
        usleep(10_000);
    }
    @fclose($h['pipes'][1]);
    @fclose($h['pipes'][2]);
    @proc_close($h['proc']);
}

function qdb_daemon_running(): bool
{
    $h = $GLOBALS['__qdbd'];
    if ($h === null) {
        return false;
    }
    $st = proc_get_status($h['proc']);
    return (bool) ($st['running'] ?? false);
}

/**
 * Seed a node directly on the filesystem (bypassing the API on purpose).
 * In daemon mode this is an out-of-band write, so wait until the daemon has
 * observed it — the tests' subsequent asserts stay immediate in both modes.
 */
function seed_node(string $root, string $relpath, array $data = []): void
{
    $dir = "$root/$relpath";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents("$dir/data.json", json_encode($data));
    if (qdb_daemon_mode()) {
        $name = basename($relpath);
        $settled = qdb_settle(function () use ($name, $relpath, $data) {
            $path = QuantaDb::path($name);
            return $path !== null
                && str_ends_with($path, "/$relpath")
                && QuantaDb::get($name) === $data;
        });
        if (!$settled) {
            fwrite(STDERR, "seed_node($relpath) not observed by daemon\n");
        }
    }
}

function worker_env(array $extra = []): array
{
    return qdb_env($extra);
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
