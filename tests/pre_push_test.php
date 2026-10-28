<?php

declare(strict_types=1);

// These tests use real Git repositories and the installed PHP_CodeSniffer.
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/quanta-hook-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$passed = 0;

function execute(array $command, string $directory, string $input = ''): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if ($process === false) {
        throw new RuntimeException('Cannot start test command.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

function git(string $directory, string ...$arguments): string
{
    [$status, $output] = execute(['git', '-c', 'core.hooksPath=/dev/null', ...$arguments], $directory);
    if ($status !== 0) {
        throw new RuntimeException('Git fixture failed: ' . $output);
    }
    return trim($output);
}

function check(bool $condition, string $description, string $details = ''): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($description . "\n" . $details);
    }
    $passed++;
    echo "ok - $description\n";
}

function commitFile(string $repository, string $name, string $contents): string
{
    if (!is_dir(dirname($repository . '/' . $name))) {
        mkdir(dirname($repository . '/' . $name), 0700, true);
    }
    file_put_contents($repository . '/' . $name, $contents);
    git($repository, 'add', '--', $name);
    git($repository, 'commit', '-qm', 'Fixture change');
    return git($repository, 'rev-parse', 'HEAD');
}

function hook(string $repository, string $input, ?string $project = null): array
{
    global $root;
    return execute([($project ?? $root) . '/.githooks/pre-push', 'origin', 'unused'], $repository, $input);
}

function update(string $old, string $new): string
{
    return "refs/heads/main $new refs/heads/main $old\n";
}

try {
    $repository = $temporary . '/repository';
    mkdir($repository);
    git($repository, 'init', '-q', '-b', 'main');
    git($repository, 'config', 'user.name', 'Hook Tests');
    git($repository, 'config', 'user.email', 'hook-tests@example.invalid');
    $bad = "<?php\nfunction bad(){echo 'bad';}\n";
    $good = "<?php\n\ndeclare(strict_types=1);\n\necho \"Good style\\n\";\n";
    $base = commitFile($repository, 'legacy.php', $bad);
    git($repository, 'update-ref', 'refs/remotes/origin/main', $base);
    $zero = str_repeat('0', 40);

    [$status, $output] = hook($repository, '');
    check($status === 0, 'empty push input succeeds', $output);
    [$status, $output] = hook($repository, update($base, $zero));
    check($status === 0, 'ref deletion succeeds', $output);

    $goodCommit = commitFile($repository, 'changed.php', $good);
    file_put_contents($repository . '/changed.php', $bad);
    [$status, $output] = hook($repository, update($base, $goodCommit));
    check($status === 0, 'committed good PHP passes despite dirty PHP and untouched legacy code', $output);
    check(file_get_contents($repository . '/changed.php') === $bad, 'style check leaves dirty files untouched');
    git($repository, 'checkout', '--', 'changed.php');

    symlink($root . '/.githooks', $temporary . '/shared-hooks');
    [$status, $output] = execute(
        [$temporary . '/shared-hooks/pre-push', 'origin', 'unused'],
        $repository,
        update($base, $goodCommit)
    );
    check($status === 0, 'shared hook symlinks resolve the central Composer installation', $output);

    $badCommit = commitFile($repository, 'changed.php', $bad);
    file_put_contents($repository . '/changed.php', $good);
    [$status, $output] = hook($repository, update($goodCommit, $badCommit));
    check(
        $status !== 0 && str_contains($output, 'Push blocked'),
        'bad committed PHP fails despite a clean-style working copy',
        $output
    );
    git($repository, 'checkout', '--', 'changed.php');

    [$status, $output] = hook($repository, update($goodCommit, $badCommit) . update($base, $goodCommit));
    check($status !== 0, 'a failing ref blocks a multi-ref push', $output);
    [$status, $output] = hook($repository, update($base, $goodCommit) . update($goodCommit, $badCommit));
    check($status !== 0, 'all refs are checked even when the first passes', $output);

    git($repository, 'reset', '--hard', $goodCommit);
    $oddCommit = commitFile($repository, 'source/a space and ; dollar $.inc', $bad);
    [$status, $output] = hook($repository, update($goodCommit, $oddCommit));
    check(
        $status !== 0 && str_contains($output, 'Push blocked'),
        'PHP include files with spaces and shell characters are checked',
        $output
    );

    git($repository, 'reset', '--hard', $goodCommit);
    $newlineCommit = commitFile($repository, "source/a\nnewline.php", $bad);
    [$status, $output] = hook($repository, update($goodCommit, $newlineCommit));
    check(
        $status !== 0 && str_contains($output, 'Push blocked'),
        'filenames containing newlines are checked safely',
        $output
    );

    git($repository, 'reset', '--hard', $goodCommit);
    $uppercase = commitFile($repository, 'source/uppercase.PHP', $good);
    [$status, $output] = hook($repository, update($goodCommit, $uppercase));
    check($status === 0, 'uppercase PHP extensions are supported', $output);
    git($repository, 'reset', '--hard', $goodCommit);
    git($repository, 'mv', '--', 'changed.php', 'renamed.inc');
    git($repository, 'commit', '-qm', 'Rename fixture');
    [$status, $output] = hook($repository, update($goodCommit, git($repository, 'rev-parse', 'HEAD')));
    check($status === 0, 'renamed PHP files are checked at their new path', $output);
    git($repository, 'reset', '--hard', $goodCommit);
    symlink('legacy.php', $repository . '/type-change.php');
    git($repository, 'add', '--', 'type-change.php');
    git($repository, 'commit', '-qm', 'Add symlink fixture');
    $symlinkCommit = git($repository, 'rev-parse', 'HEAD');
    unlink($repository . '/type-change.php');
    $regularCommit = commitFile($repository, 'type-change.php', $bad);
    [$status, $output] = hook($repository, update($symlinkCommit, $regularCommit));
    check($status !== 0, 'symlinks replaced by regular PHP files are checked', $output);
    git($repository, 'reset', '--hard', $goodCommit);
    git($repository, 'update-ref', 'refs/remotes/origin/main', $goodCommit);
    $branchCommit = commitFile($repository, 'new-branch.inc', $good);
    [$status, $output] = hook($repository, update($zero, $branchCommit));
    check($status === 0, 'new branch checks only commits not already on the remote', $output);
    git($repository, 'update-ref', 'refs/remotes/upstream/main', $goodCommit);
    git($repository, 'update-ref', '-d', 'refs/remotes/origin/main');
    [$status, $output] = hook($repository, update($zero, $branchCommit));
    check($status === 0, 'first push to a fork can use fetched upstream refs', $output);
    git($repository, 'update-ref', 'refs/remotes/origin/main', $goodCommit);
    $branchBad = commitFile($repository, 'new-branch.inc', $bad);
    [$status, $output] = hook($repository, update($zero, $branchBad));
    check($status !== 0, 'new branch with incorrect PHP is blocked', $output);
    git($repository, 'rm', '--', 'new-branch.inc');
    git($repository, 'commit', '-qm', 'Delete fixture');
    $deleted = git($repository, 'rev-parse', 'HEAD');
    [$status, $output] = hook($repository, update($zero, $deleted));
    check($status === 0, 'files deleted at the pushed tip are skipped', $output);

    [$status, $output] = hook($repository, update(str_repeat('1', 40), $goodCommit));
    check(
        $status !== 0 && str_contains($output, 'Fetch the remote'),
        'missing remote commit fails with a useful fetch instruction',
        $output
    );
    [$status, $output] = hook($repository, "malformed input\n");
    check($status !== 0, 'invalid Git hook input fails closed', $output);

    $withoutTools = $temporary . '/without-tools';
    mkdir($withoutTools . '/.githooks', 0700, true);
    mkdir($withoutTools . '/scripts');
    copy($root . '/.githooks/pre-push', $withoutTools . '/.githooks/pre-push');
    chmod($withoutTools . '/.githooks/pre-push', 0755);
    copy($root . '/scripts/check-push-style.php', $withoutTools . '/scripts/check-push-style.php');
    [$status, $output] = hook($repository, update($base, $goodCommit), $withoutTools);
    check(
        $status !== 0 && str_contains($output, 'composer install'),
        'missing PHP_CodeSniffer blocks PHP changes with setup guidance',
        $output
    );
    $documentation = commitFile($repository, 'README.txt', "Documentation only\n");
    [$status, $output] = hook($repository, update($deleted, $documentation), $withoutTools);
    check($status === 0, 'documentation-only push works without PHP_CodeSniffer', $output);

    $remote = $temporary . '/remote.git';
    git($temporary, 'init', '-q', '--bare', $remote);
    git($repository, 'remote', 'add', 'origin', $remote);
    git($repository, 'config', 'core.hooksPath', $root . '/.githooks');
    git($repository, 'push', 'origin', $base . ':refs/heads/main');
    git($repository, 'fetch', 'origin');
    // Use Git's actual hook protocol for the final integration check.
    [$status, $output] = execute(['git', 'push', 'origin', $goodCommit . ':refs/heads/main'], $repository);
    check($status === 0, 'real git push accepts correctly styled committed PHP', $output);
    [$status, $output] = execute(['git', 'push', 'origin', $branchBad . ':refs/heads/check'], $repository);
    check(
        $status !== 0 && str_contains($output, 'Push blocked'),
        'real git push is blocked by the installed hook',
        $output
    );
    echo "$passed pre-push regression checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($temporary);
}
