<?php

require __DIR__ . '/fixture.php';
$source = dirname(__DIR__, 2);
if (PHP_OS_FAMILY === 'Windows' || !is_file($source . '/vendor/bin/behat')) {
    fwrite(STDERR, "Requires Unix, Apache with PHP/rewrite/headers, and Composer development dependencies. See tests/behat/README.md.\n");
    exit(1);
}
if ($connection = @fsockopen('127.0.0.1', 8080, $error, $message, 0.2)) {
    fclose($connection);
    throw new RuntimeException('Port 8080 is already in use; no existing server will be contacted or stopped.');
}
$root = rtrim(sys_get_temp_dir(), '/') . '/quanta-behat-' . bin2hex(random_bytes(6));
mkdir($root, 0770);
fixtureWrite($root . '/.quanta-behat-owned', 'Disposable Quanta Behat fixture');
$server = null;
$exit = 1;
try {
    mkdir($root . '/app', 0770);
    mkdir($root . '/apache-run', 0770);
    mkdir($root . '/sessions', 0770);
    foreach (['src', 'vendor', 'profiles', '.htaccess'] as $name) {
        if (!symlink($source . '/' . $name, $root . '/app/' . $name)) {
            throw new RuntimeException('Cannot link application source into the fixture.');
        }
    }
    putenv('QUANTA_BEHAT_ROOT=' . $root);
    putenv('QUANTA_BEHAT_URL=http://127.0.0.1:8080');
    $seed = proc_open([PHP_BINARY, __DIR__ . '/seed.php', $root], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($seed) || proc_close($seed) !== 0) {
        throw new RuntimeException('Initial fixture creation failed.');
    }
    $uid = posix_geteuid();
    $gid = posix_getegid();
    if ($uid === 0) {
        throw new RuntimeException('Run the test container as its default non-root www-data user.');
    }
    $config = <<<APACHE
    ServerRoot "/etc/apache2"
    ServerName 127.0.0.1
    Listen 127.0.0.1:8080
    PidFile "$root/apache-run/apache.pid"
    DefaultRuntimeDir "$root/apache-run"
    ErrorLog "$root/apache.log"
    LogLevel warn
    IncludeOptional /etc/apache2/mods-enabled/*.load
    IncludeOptional /etc/apache2/mods-enabled/*.conf
    User #$uid
    Group #$gid
    DocumentRoot "$root/app"
    <Directory "$root/app">
        AllowOverride All
        Require all granted
        Options FollowSymLinks
    </Directory>
    php_admin_value session.save_path "$root/sessions"
    php_admin_flag display_errors Off
    APACHE;
    fixtureWrite($root . '/apache.conf', $config);
    putenv('APACHE_RUN_DIR=' . $root . '/apache-run');
    putenv('APACHE_LOG_DIR=' . $root);
    putenv('APACHE_PID_FILE=' . $root . '/apache-run/apache.pid');
    $server = proc_open(['apache2', '-f', $root . '/apache.conf', '-DFOREGROUND'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'],
            2 => ['file', $root . '/server.log', 'a']], $pipes, $root . '/app');
    if (!is_resource($server)) {
        throw new RuntimeException('Apache could not start.');
    }
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        if (!proc_get_status($server)['running']) {
            break;
        }
        if ($connection = @fsockopen('127.0.0.1', 8080, $error, $message, 0.1)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    if (!$ready) {
        throw new RuntimeException('Owned Apache server did not become ready.');
    }
    echo 'Real Apache/PHP HTTP fixture: ' . $root . "\n";
    $arguments = array_merge([PHP_BINARY, $source . '/vendor/bin/behat', '--strict', '--no-interaction'], array_slice($argv, 1));
    $behat = proc_open($arguments, [STDIN, STDOUT, STDERR], $pipes, $source);
    $exit = is_resource($behat) ? proc_close($behat) : 1;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($exit !== 0) {
        foreach (['server.log', 'apache.log'] as $log) {
            if (is_file($root . '/' . $log)) {
                fwrite(STDERR, "\n$log (last 120 lines):\n" . implode('', array_slice(file($root . '/' . $log), -120)));
            }
        }
    }
    foreach (new FilesystemIterator($root) as $entry) {
        if ($entry->getFilename() !== '.quanta-behat-owned') {
            fixtureRemove($entry->getPathname(), $root);
        }
    }
    unlink($root . '/.quanta-behat-owned');
    rmdir($root);
    echo "Owned fixture removed; application source untouched.\n";
}
exit($exit === 0 ? 0 : 1);
