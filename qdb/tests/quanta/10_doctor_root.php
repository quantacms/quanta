<?php
/**
 * doctor started as root does its work as the site's owner.
 *
 * `kubectl exec <pod> -- php doctor <site> <command>` starts PHP as root, and
 * what that process writes (the class map, caches, node documents, the
 * quanta_db lock files) then belongs to root. php-fpm runs as the site's user
 * and gets EACCES on it: empty 500s on every request that writes, until someone
 * chowns it back. So doctor started as root becomes the owner of the site dir
 * before the CMS boots. QUANTA_DOCTOR_AS_ROOT=1 keeps root, for a caller that
 * fixes ownership afterwards (the container entrypoint), and a site dir that
 * root owns (an image build) needs no switch.
 *
 * The class map is the witness: `doctor class_map` swaps in a new file every
 * time, so the file belongs to whoever the doctor process ran as.
 */
require __DIR__ . '/_bootstrap.php';

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    echo "  skip - needs root\n";
    finish();
}
$app = posix_getpwnam('www-data');
if ($app === FALSE) {
    echo "  skip - no www-data user to own the site\n";
    finish();
}

$quanta = quanta_root();
$host = 'qdbtest-' . bin2hex(random_bytes(4));
$site = "$quanta/sites/$host";
$tmp = "$quanta/static/tmp/$host";
$map = "$tmp/class_map.dat";
foreach (array("$site/db/_languages", "$site/db/_translations", "$site/db/_users", "$site/_modules", "$site/_tpl", $tmp) as $dir) {
    mkdir($dir, 0755, TRUE);
}
$GLOBALS['__site'] = array('site' => $site, 'tmp' => $tmp, 'base' => sys_get_temp_dir() . "/qdbq-$host");
register_shutdown_function('quanta_cleanup');
// As the container entrypoint leaves a site: all of it the site user's.
exec('chown -R www-data:www-data ' . escapeshellarg($site) . ' ' . escapeshellarg($tmp));

/**
 * Run `php doctor <host> class_map` as this (root) process would from a shell.
 *
 * @return array
 *   [exit status, stdout, stderr].
 */
function run_doctor(string $host, array $env = array()): array
{
    $cmd = array(PHP_BINARY);
    if (qdb_ext()) {
        // Point the extension at this site. Passed with -d, so these also prove
        // the command line survives the switch to the site's owner.
        $s = $GLOBALS['__site'];
        $ini = array(
            'root' => $s['site'],
            'shm_dir' => "{$s['base']}/shm",
            'socket_path' => "{$s['base']}/qdbd.sock",
            'metrics_path' => "{$s['base']}/metrics.shm",
            'lock_dir' => "{$s['base']}/locks",
            'trashbin_dir' => "{$s['base']}/trash",
        );
        foreach ($ini as $k => $v) {
            $cmd[] = '-d';
            $cmd[] = "quanta_db.$k=$v";
        }
    }
    array_push($cmd, 'doctor', $host, 'class_map');
    $proc = proc_open(
        $cmd,
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        quanta_root(),
        array_merge(getenv(), $env)
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($proc), $out, $err);
}

function owner_of(string $path): string
{
    clearstatcache();
    $st = @stat($path);
    if ($st === FALSE) {
        return 'missing';
    }
    $pw = posix_getpwuid($st['uid']);
    return $pw === FALSE ? (string) $st['uid'] : $pw['name'];
}

function doctor_ran(array $run, string $label): void
{
    list($code, $out, $err) = $run;
    if ($code !== 0) {
        echo "    exit $code\n    stdout: " . trim($out) . "\n    stderr: " . trim($err) . "\n";
    }
    eq($code, 0, "$label: doctor exits 0");
    ok(strpos($out, 'Doctor has finished') !== FALSE, "$label: doctor's own output comes through");
}

// ── root, site owned by www-data: doctor works as www-data ───────────────────
$run = run_doctor($host);
doctor_ran($run, 'as root');
eq(owner_of($map), 'www-data', 'as root, the class map doctor wrote belongs to the site owner');
eq(owner_of("$tmp/doctor_recipe.txt"), 'www-data', 'as root, the doctor recipe belongs to the site owner');

// ── QUANTA_DOCTOR_AS_ROOT=1: the caller keeps root on purpose ─────────────────
$run = run_doctor($host, array('QUANTA_DOCTOR_AS_ROOT' => '1'));
doctor_ran($run, 'QUANTA_DOCTOR_AS_ROOT=1');
eq(owner_of($map), 'root', 'QUANTA_DOCTOR_AS_ROOT=1 keeps root');

// ── a site root owns (an image build): nothing to switch to ──────────────────
chown($site, 'root');
$run = run_doctor($host);
doctor_ran($run, 'root-owned site');
eq(owner_of($map), 'root', 'a root-owned site keeps root');

finish();
