<?php

/**
 * Local Git/PHPCS integration tests; no network or application bootstrap.
 * Run: php tests/pre_push_test.php [path/to/phpcs]
 * TMPDIR/TEMP controls the retained, isolated fixture directory.
 */
$checker = realpath($argv[1] ?? __DIR__ . '/../vendor/bin/phpcs');
if ($checker === false) {
    fwrite(STDERR, "Install Composer development dependencies or pass a real PHPCS executable.\n");
    exit(1);
}
$source = dirname(__DIR__);
$root = sys_get_temp_dir() . '/quanta-pre-push-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$passed = 0;
$failed = 0;
$filter = getenv('QUANTA_TEST_FILTER');
$good = "<?php\n\nnamespace QuantaHookFixture;\n\nclass Good\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n";
$bad = "<?php\nnamespace QuantaHookFixture;\nclass Bad{public function value(){return 1;}}\n";

function command(array $args, string $cwd): array
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $args[0]);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out . $err];
}

function git(string $cwd, string ...$args): string
{
    [$code, $output] = command(array_merge(['git'], $args), $cwd);
    if ($code !== 0) {
        throw new RuntimeException('git ' . implode(' ', $args) . " failed:\n" . $output);
    }
    return trim($output);
}

function writeFile(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
}

function installChecker(string $project): void
{
    global $checker;
    // This launcher invokes the unmodified real checker, including its autoloader.
    $trace = var_export($project . '/phpcs-calls.log', true);
    writeFile($project . '/vendor/bin/phpcs', "#!/usr/bin/env php\n<?php\nfile_put_contents($trace, \"call\\n\", FILE_APPEND);\nrequire " . var_export($checker, true) . ";\n");
    chmod($project . '/vendor/bin/phpcs', 0755);
}

function fixture(bool $nested = false, bool $withChecker = true): array
{
    global $root, $source;
    static $sequence = 0;
    $base = $root . '/case-' . ++$sequence;
    $project = $base . '/quanta project';
    $repo = $nested ? $project . '/sites/example.test' : $project;
    $remote = $base . '/remote.git';
    mkdir($repo, 0777, true);
    git($base, 'init', '--bare', '--initial-branch=main', $remote);
    git($repo, 'init', '--initial-branch=main');
    git($repo, 'config', 'user.name', 'Hook integration fixture');
    git($repo, 'config', 'user.email', 'hook-fixture@example.invalid');
    git($repo, 'config', 'commit.gpgSign', 'false');
    git($repo, 'config', 'core.autocrlf', 'false');
    git($repo, 'remote', 'add', 'origin', $remote);
    copy($source . '/.phpcs.xml', $project . '/.phpcs.xml');
    mkdir($project . '/.githooks');
    foreach (['pre-push', 'check-style.php'] as $name) {
        if (is_file($source . '/.githooks/' . $name)) {
            $hookSource = $name === 'pre-push' && getenv('QUANTA_TEST_HOOK_PATH')
                ? getenv('QUANTA_TEST_HOOK_PATH') : $source . '/.githooks/' . $name;
            copy($hookSource, $project . '/.githooks/' . $name);
            chmod($project . '/.githooks/' . $name, 0755);
        }
    }
    git($repo, 'config', 'core.hooksPath', $nested ? '../../.githooks' : '.githooks');
    if ($withChecker) {
        installChecker($project);
    }
    return compact('base', 'project', 'repo', 'remote');
}

function commitFile(array $f, string $path, string $body): string
{
    writeFile($f['repo'] . '/' . $path, $body);
    git($f['repo'], 'add', '--', $path);
    git($f['repo'], 'commit', '-m', 'Synthetic hook fixture');
    return git($f['repo'], 'rev-parse', 'HEAD');
}

function push(array $f, string ...$refs): array
{
    return command(array_merge(['git', 'push', 'origin'], $refs ?: ['HEAD:main']), $f['repo']);
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejected(array $f, string ...$refs): void
{
    $before = git($f['base'], '--git-dir=' . $f['remote'], 'for-each-ref', '--format=%(refname):%(objectname)');
    [$code, $output] = push($f, ...$refs);
    expect($code !== 0, "Invalid push was accepted:\n" . $output);
    expect(str_contains($output, 'phpcs') || str_contains($output, 'PHPCS') || str_contains($output, 'ERROR'), $output);
    expect(git($f['base'], '--git-dir=' . $f['remote'], 'for-each-ref', '--format=%(refname):%(objectname)') === $before, 'Rejected push changed the remote');
}

function test(string $name, callable $run): void
{
    global $passed, $failed, $filter;
    if ($filter && !preg_match($filter, $name)) {
        return;
    }
    try {
        $run();
        ++$passed;
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
}

[$versionCode, $version] = command([PHP_BINARY, $checker, '--version'], $root);
expect($versionCode === 0 && str_contains($version, 'PHP_CodeSniffer version'), 'A real PHPCS installation is required');
echo PHP_VERSION . ' / ' . trim($version) . "\nFixtures retained at $root\n";

test('root accepts valid PHP', function () use ($good) {
    $f = fixture();
    $commit = commitFile($f, 'src/Good.php', $good);
    [$code, $output] = push($f);
    expect($code === 0, $output);
    expect(file_get_contents($f['project'] . '/phpcs-calls.log') === "call\n", 'The real checker was not invoked exactly once');
    expect(git($f['base'], '--git-dir=' . $f['remote'], 'rev-parse', 'main') === $commit, 'Remote did not receive the valid commit');
});
test('root rejects PHPCS-invalid PHP', function () use ($bad) {
    $f = fixture();
    commitFile($f, 'src/Bad.php', $bad);
    rejected($f);
});
test('nested site accepts valid PHP using the parent checker and rules', function () use ($good) {
    $f = fixture(true);
    commitFile($f, 'src/Good.php', $good);
    [$code, $output] = push($f);
    expect($code === 0, $output);
});
test('nested site rejects PHPCS-invalid PHP', function () use ($bad) {
    $f = fixture(true);
    commitFile($f, 'src/Bad.php', $bad);
    rejected($f);
});
test('missing checker rejects a PHP push', function () use ($good) {
    $f = fixture(false, false);
    commitFile($f, 'src/Good.php', $good);
    rejected($f);
});
test('uncommitted correction cannot conceal an invalid commit', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Bad.php', $bad);
    writeFile($f['repo'] . '/src/Bad.php', $good);
    rejected($f);
});
test('an invalid outgoing branch is checked instead of the checked-out branch', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    git($f['repo'], 'checkout', '-b', 'bad-branch');
    commitFile($f, 'src/Bad.php', $bad);
    git($f['repo'], 'checkout', 'main');
    rejected($f, 'bad-branch:bad-branch');
});
test('invalid annotated tag target is rejected', function () use ($bad) {
    $f = fixture();
    commitFile($f, 'src/Bad.php', $bad);
    git($f['repo'], 'tag', '-a', 'bad-tag', '-m', 'Synthetic tag');
    rejected($f, 'bad-tag');
});
test('one invalid ref rejects the entire multi-ref push', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    git($f['repo'], 'checkout', '-b', 'bad-branch');
    commitFile($f, 'src/Bad.php', $bad);
    git($f['repo'], 'checkout', 'main');
    rejected($f, 'main:main', 'bad-branch:bad-branch');
});
test('space-containing paths cannot evade PHPCS', function () use ($bad) {
    $f = fixture();
    commitFile($f, 'src/space directory/Bad file.php', $bad);
    rejected($f);
});
test('dirty invalid worktree does not replace valid pushed content', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    writeFile($f['repo'] . '/src/Good.php', $bad);
    [$code, $output] = push($f);
    expect($code === 0, $output);
});
test('existing Quanta test-directory exclusion is preserved', function () use ($bad) {
    $f = fixture();
    commitFile($f, 'tests/Excluded.php', $bad);
    [$code, $output] = push($f);
    expect($code === 0, $output);
});
test('non-code-only pushes do not require the checker', function () {
    $f = fixture(false, false);
    commitFile($f, 'README.md', "Documentation only.\n");
    [$code, $output] = push($f);
    expect($code === 0, $output);
});
test('no-op and deletion pushes do not require the checker', function () use ($good) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    [$code, $output] = push($f);
    expect($code === 0, $output);
    unlink($f['project'] . '/vendor/bin/phpcs');
    [$code, $output] = push($f);
    expect($code === 0, $output);
    git($f['base'], '--git-dir=' . $f['remote'], 'config', 'receive.denyDeleteCurrent', 'ignore');
    [$code, $output] = push($f, ':main');
    expect($code === 0, $output);
    expect(git($f['base'], '--git-dir=' . $f['remote'], 'for-each-ref', '--format=%(refname)') === '', 'Deletion did not remove the ref');
});
test('invalid update cannot change an existing remote branch', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    [$code, $output] = push($f);
    expect($code === 0, $output);
    commitFile($f, 'src/Bad.php', $bad);
    rejected($f);
});
test('new branch checks changed code without imposing a legacy formatting sweep', function () use ($good, $bad) {
    $f = fixture();
    commitFile($f, 'src/Legacy.php', $bad);
    // Seed historical content directly into the bare fixture, before testing the
    // new branch. This does not disable a hook in any real development repository.
    git($f['base'], '--git-dir=' . $f['remote'], 'fetch', $f['repo'], 'HEAD:refs/heads/main');
    git($f['repo'], 'fetch', 'origin');
    git($f['repo'], 'checkout', '-b', 'new-work');
    commitFile($f, 'src/Good.php', $good);
    [$code, $output] = push($f, 'new-work');
    expect($code === 0, $output);
    expect(file_get_contents($f['project'] . '/phpcs-calls.log') === "call\n", 'Unchanged legacy code was checked or new code was missed');
});
test('nested local checker failure never falls through to the parent checker', function () use ($bad) {
    $f = fixture(true);
    installChecker($f['repo']);
    commitFile($f, 'src/Bad.php', $bad);
    rejected($f);
    expect(file_get_contents($f['repo'] . '/phpcs-calls.log') === "call\n", 'Local checker not used');
    expect(!file_exists($f['project'] . '/phpcs-calls.log'), 'Parent checker incorrectly used after local failure');
});
test('missing project rules reject a PHP push instead of using an unrelated default', function () use ($good) {
    $f = fixture();
    commitFile($f, 'src/Good.php', $good);
    unlink($f['project'] . '/.phpcs.xml');
    rejected($f);
});

echo "$passed passed; $failed failed\n";
exit($failed === 0 ? 0 : 1);
