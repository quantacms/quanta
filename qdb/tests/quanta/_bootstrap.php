<?php
/**
 * Bootstrap for the Quanta call-site parity suite.
 *
 * Where qdb/tests/php/ proves the EXTENSION obeys the contract, this suite
 * proves QUANTA obeys itself: every call site wired to the node database must
 * give the same answer whether the extension is loaded or not. Each test runs
 * three times (see run-quanta-tests.sh):
 *
 *   noext    — the .so is not loaded at all; every shim takes its legacy path.
 *   fallback — the extension is loaded with no daemon; it serves from a
 *              per-process filesystem walk.
 *   daemon   — a qdbd owns the tree in shared memory and is authoritative.
 *
 * A test asserts BEHAVIOUR, which must be identical in all three. Where it
 * wants to prove the extension path was actually taken (rather than the shim
 * silently falling back), it guards the assertion with qdb_ext() — those are
 * the discriminators, and they are skipped in noext mode.
 *
 * Each file runs as its own PHP process, because extension config binds once
 * per process and Quanta's Environment is a process-wide singleton in practice.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__skip'] = 0;
$GLOBALS['__qdbd'] = NULL;
$GLOBALS['__site'] = NULL;

/** Quanta checkout root; the tests run from inside it. */
function quanta_root(): string
{
    return getenv('QUANTA_ROOT') ?: '/var/www/quanta';
}

/** TRUE when the native extension is loaded in this process. */
function qdb_ext(): bool
{
    return extension_loaded('quanta_db');
}

/** TRUE when this run expects a coherent daemon. */
function qdb_daemon_mode(): bool
{
    return getenv('QDB_MODE') === 'daemon' && qdb_ext();
}

/** The mode name, for output. */
function qdb_mode_name(): string
{
    if (!qdb_ext()) {
        return 'noext';
    }
    return qdb_daemon_mode() ? 'daemon' : 'fallback';
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
        echo "    expected: " . var_export($expected, TRUE) . "\n";
        echo "    actual:   " . var_export($actual, TRUE) . "\n";
    }
    ok($cond, $label);
}

/**
 * An assertion that only means something with the extension loaded.
 *
 * The condition is a CALLABLE, not a value: these assertions name QuantaDb,
 * which does not exist in noext mode, so the expression must not be evaluated
 * before the skip decision. In noext mode they count as skipped, not passed,
 * so the output makes the difference between the modes visible.
 */
function ok_ext(callable $cond, string $label): void
{
    if (!qdb_ext()) {
        $GLOBALS['__skip']++;
        echo "  skip - $label (no extension)\n";
        return;
    }
    ok((bool) $cond(), $label);
}

function eq_ext(callable $actual, $expected, string $label): void
{
    if (!qdb_ext()) {
        $GLOBALS['__skip']++;
        echo "  skip - $label (no extension)\n";
        return;
    }
    eq($actual(), $expected, $label);
}

/**
 * An assertion that only holds while a daemon owns the tree.
 *
 * In fallback mode the extension answers from a per-process filesystem walk
 * and keeps no durable index, so index bookkeeping — generation counters above
 * all — is not maintained. Asserting it there tests the absence of a daemon,
 * not the code under test.
 */
function ok_daemon(callable $cond, string $label): void
{
    if (!qdb_daemon_mode()) {
        $GLOBALS['__skip']++;
        echo "  skip - $label (needs a coherent daemon)\n";
        return;
    }
    ok((bool) $cond(), $label);
}

/**
 * Did the extension's serializer write this document, or PHP's json_encode?
 *
 * The cleanest discriminator that works in every extension mode: json_encode
 * escapes '/' as '\/' and non-ASCII as \uXXXX, and the extension's serializer
 * escapes neither (qdb/docs/usage.md §7). So a document containing both,
 * read back as raw bytes, says which writer produced it.
 */
function written_by_extension(string $raw): bool
{
    return strpos($raw, '\\/') === FALSE && strpos($raw, '\\u') === FALSE;
}

/**
 * The extension's live operation counters.
 *
 * These are how a test proves a call site reached the extension at all, rather
 * than parity holding because both branches happen to agree. A shim that
 * quietly fell back would leave every counter where it was.
 *
 * @return array
 *   The stats array, or [] when there is no extension.
 */
function counters(): array
{
    return qdb_ext() ? (array) \QuantaDb::stats() : array();
}

/**
 * Assert a counter moved between two snapshots.
 *
 * @param array $before
 *   counters() taken before the operation.
 * @param string $key
 *   The counter name.
 * @param string $label
 *   The assertion label.
 * @param int $by
 *   Minimum increase.
 */
function grew(array $before, string $key, string $label, int $by = 1): void
{
    if (!qdb_ext()) {
        $GLOBALS['__skip']++;
        echo "  skip - $label (no extension)\n";
        return;
    }
    $after = counters();
    $from = (int) ($before[$key] ?? 0);
    $to = (int) ($after[$key] ?? 0);
    if ($to < $from + $by) {
        echo "    $key: $from -> $to (wanted at least " . ($from + $by) . ")\n";
    }
    ok($to >= $from + $by, $label);
}

/** Assert a counter did NOT move — e.g. no filesystem read happened. */
function unchanged(array $before, string $key, string $label): void
{
    if (!qdb_daemon_mode()) {
        $GLOBALS['__skip']++;
        echo "  skip - $label (needs a coherent daemon)\n";
        return;
    }
    $after = counters();
    $from = (int) ($before[$key] ?? 0);
    $to = (int) ($after[$key] ?? 0);
    if ($to !== $from) {
        echo "    $key: $from -> $to (wanted no change)\n";
    }
    ok($to === $from, $label);
}

/**
 * Out-of-band filesystem changes reach a daemon asynchronously. Only daemon
 * mode waits; the other two are immediate.
 */
function settle(callable $cond, float $budget = 3.0): bool
{
    $deadline = microtime(TRUE) + (qdb_daemon_mode() ? $budget : 0.0);
    while (TRUE) {
        try {
            if ($cond()) {
                return TRUE;
            }
        } catch (\Throwable $e) {
            // Not settled yet — a half-written document can throw CORRUPT_JSON
            // for a few ms. Never a reason to give up early.
        }
        if (microtime(TRUE) >= $deadline) {
            return FALSE;
        }
        usleep(20000);
    }
}

/**
 * Wait until the daemon has observed exactly $langs for $name ('' = neutral).
 *
 * A node's directory is indexed before the documents inside it: the daemon
 * watches a new dir, then walks it, and any data*.json written after that walk
 * arrives as its own event. So `path($name) !== NULL` says the node is there,
 * not that the documents a test just wrote next to it are — and a call site
 * whose decision depends on the language list (integrity's collapse) will act
 * on a short list and leave a translation behind. Seed with this instead
 * whenever the test writes documents out of band and then reads the index.
 */
function settle_langs(string $name, array $langs): bool
{
    if (!qdb_daemon_mode()) {
        return TRUE;
    }
    sort($langs);
    $seen = function () use ($name) {
        $meta = \QuantaDb::meta($name);
        $langs = $meta === NULL ? array() : $meta['langs'];
        sort($langs);
        return $langs;
    };
    $settled = settle(fn() => $seen() === $langs);
    if (!$settled) {
        fwrite(STDERR, "    daemon never observed langs ["
            . implode(',', $langs) . "] for $name (saw ["
            . implode(',', $seen()) . "])\n");
    }
    return $settled;
}

function ok_eventually(callable $fn, string $label): void
{
    settle(fn() => (bool) $fn());
    ok((bool) $fn(), $label);
}

function eq_eventually(callable $fn, $expected, string $label): void
{
    settle(fn() => $fn() === $expected);
    eq($fn(), $expected, $label);
}

/**
 * Build a throwaway Quanta site and boot a real Environment against it.
 *
 * The site lives under the checkout's own sites/ dir, so src/, profiles/ and
 * vendor/ are the real ones and every class under test is the shipped class.
 *
 * @return array
 *   [Environment $env, string $site_dir, string $db_dir]
 */
function quanta_env(): array
{
    $quanta = quanta_root();
    $host = 'qdbtest-' . bin2hex(random_bytes(4));
    $site = "$quanta/sites/$host";
    $tmp = "$quanta/static/tmp/$host";

    // The site skeleton a booted Environment expects. _languages and
    // _translations exist because Localization reads them; without them the
    // modules warn on every request path that negotiates a language.
    $skeleton = array(
        "$site/db",
        "$site/db/_languages",
        "$site/db/_translations",
        "$site/db/_users",
        "$site/_modules",
        "$site/_tpl",
        $tmp,
    );
    foreach ($skeleton as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0777, TRUE)) {
            fwrite(STDERR, "cannot create $dir\n");
            exit(1);
        }
    }
    $GLOBALS['__site'] = array('site' => $site, 'tmp' => $tmp);
    register_shutdown_function('quanta_cleanup');

    // Point the extension at THIS site before anything touches QuantaDb:
    // config binds process-wide at the first call and is silently ignored
    // afterwards (qdb/docs/usage.md §3).
    if (qdb_ext()) {
        $base = sys_get_temp_dir() . '/qdbq-' . basename($site);
        ini_set('quanta_db.root', $site);
        ini_set('quanta_db.shm_dir', "$base/shm");
        ini_set('quanta_db.socket_path', "$base/qdbd.sock");
        ini_set('quanta_db.metrics_path', "$base/metrics.shm");
        ini_set('quanta_db.lock_dir', "$base/locks");
        ini_set('quanta_db.trashbin_dir', "$base/trash");
        putenv("QUANTA_DB_ROOT=$site");
        $GLOBALS['__site']['base'] = $base;
        if (qdb_daemon_mode()) {
            qdbd_start();
        }
    }

    require_once "$quanta/src/modules/environment/classes/Common/DataContainer.class.php";
    require_once "$quanta/src/modules/environment/classes/Common/Environment.class.php";
    $_SERVER['HTTPS'] = 1;
    $env = new \Quanta\Common\Environment($host, NULL, $quanta);
    require_once "$quanta/src/autoload.php";
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

    // localization_init() normally registers these while serving a request;
    // load() alone does not run it, and Localization::getEnabledLanguages()
    // reads dir['languages'] unguarded.
    $env->sysdir('languages', \Quanta\Common\Localization::$dir_languages);
    $env->sysdir('translations', \Quanta\Common\Localization::$dir_translations);

    // Pin the request language so the suite is deterministic. Left unset,
    // getLanguage() negotiates its way to getFallbackLanguage() and the tests
    // would assert against a different document file depending on what the
    // throwaway site happens to contain.
    $env->setData('language', \Quanta\Common\Localization::LANGUAGE_NEUTRAL);

    return array($env, $site, "$site/db");
}

/** Spawn qdbd for the configured root and wait until it publishes coherence. */
function qdbd_start(): void
{
    $bin = getenv('QDBD_BIN') ?: 'qdbd';
    $base = $GLOBALS['__site']['base'];
    $site = $GLOBALS['__site']['site'];
    $proc = proc_open(
        array($bin),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        NULL,
        array(
            'PATH' => (string) getenv('PATH'),
            'QUANTA_DB_ROOT' => $site,
            'QUANTA_DB_SHM_DIR' => "$base/shm",
            'QUANTA_DB_SOCKET_PATH' => "$base/qdbd.sock",
            'QUANTA_DB_METRICS_PATH' => "$base/metrics.shm",
            'QUANTA_DB_LOCK_DIR' => "$base/locks",
            'QUANTA_DB_TRASHBIN_DIR' => "$base/trash",
        )
    );
    if ($proc === FALSE) {
        fwrite(STDERR, "cannot spawn qdbd ($bin)\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    $GLOBALS['__qdbd'] = array('proc' => $proc, 'pipes' => $pipes);
    $deadline = microtime(TRUE) + 10.0;
    while (!\QuantaDb::coherent()) {
        if (microtime(TRUE) >= $deadline) {
            fwrite(STDERR, "qdbd did not become coherent in 10s\n"
                . (string) stream_get_contents($pipes[2]) . "\n");
            exit(1);
        }
        usleep(20000);
    }
}

function qdbd_stop(): void
{
    $h = $GLOBALS['__qdbd'];
    if ($h === NULL) {
        return;
    }
    $GLOBALS['__qdbd'] = NULL;
    @proc_terminate($h['proc'], 15);
    $deadline = microtime(TRUE) + 5.0;
    while (microtime(TRUE) < $deadline) {
        $st = proc_get_status($h['proc']);
        if (!$st['running']) {
            break;
        }
        usleep(10000);
    }
    @fclose($h['pipes'][1]);
    @fclose($h['pipes'][2]);
    @proc_close($h['proc']);
}

/**
 * Seed a node the way legacy code does — plain mkdir + file_put_contents,
 * bypassing the API on purpose, so the tests also cover the index self-heal.
 */
function seed(string $db, string $relpath, array $data = array(), ?string $lang = NULL): string
{
    $dir = "$db/$relpath";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, TRUE);
    }
    $file = 'data' . ($lang === NULL ? '' : "_$lang") . '.json';
    file_put_contents("$dir/$file", json_encode($data));
    if (qdb_daemon_mode()) {
        // Wait for the DOCUMENT, not just the node: the dir is indexed before
        // the file written into it (see settle_langs()), so settling on path()
        // alone hands the test a node whose document the index has not read yet.
        $name = basename($relpath);
        $settled = settle(function () use ($name, $relpath, $lang, $data) {
            $path = \QuantaDb::path($name);
            return $path !== NULL
                && str_ends_with($path, "/$relpath")
                && \QuantaDb::get($name, $lang) === $data;
        });
        if (!$settled) {
            fwrite(STDERR, "    seed($relpath"
                . ($lang === NULL ? '' : ", $lang") . ") not observed by the daemon\n");
        }
    }
    return $dir;
}

function rrmdir(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff((array) @scandir($path), array('.', '..')) as $entry) {
        $child = "$path/$entry";
        if (is_link($child) || !is_dir($child)) {
            @unlink($child);
        } else {
            rrmdir($child);
        }
    }
    @rmdir($path);
}

function quanta_cleanup(): void
{
    qdbd_stop();
    $s = $GLOBALS['__site'];
    if ($s === NULL) {
        return;
    }
    $GLOBALS['__site'] = NULL;
    rrmdir($s['site']);
    rrmdir($s['tmp']);
    if (isset($s['base'])) {
        rrmdir($s['base']);
    }
}

function finish(): void
{
    quanta_cleanup();
    $f = $GLOBALS['__fail'];
    $s = $GLOBALS['__skip'];
    echo ($f ? "FAILED ($f)" : 'PASSED')
        . " - {$GLOBALS['__pass']} ok, $f failed, $s skipped [" . qdb_mode_name() . "]\n";
    exit($f ? 1 : 0);
}
