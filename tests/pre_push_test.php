<?php

// Standalone integration checks: php tests/pre_push_test.php [path-to-phpcs] [hook-path]
// All Git repositories, configuration and pushes are disposable and local.

declare(strict_types=1);

$project = dirname(__DIR__);
$phpcs = realpath($argv[1] ?? $project . '/vendor/bin/phpcs');
$hook = realpath($argv[2] ?? $project . '/.githooks/pre-push');
if ($phpcs === false || $hook === false) {
    fwrite(STDERR, "Install development dependencies or supply a PHPCS executable path.\n");
    exit(1);
}

$parent = realpath(sys_get_temp_dir());
$fixture = $parent . DIRECTORY_SEPARATOR . 'quanta-phpcs-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$repo = $fixture . '/repo';
$remote = $fixture . '/remote.git';
mkdir($repo);
$checks = [];

function command(array $args, string $cwd, string $input = ''): array
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start test command.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function git(array $args): string
{
    global $repo;
    $result = command(array_merge(['git', '-c', 'core.autocrlf=false'], $args), $repo);
    if ($result['exit'] !== 0) {
        throw new RuntimeException($result['stderr']);
    }
    return trim($result['stdout']);
}

function save(string $path, string $content): void
{
    global $repo;
    $directory = dirname($repo . '/' . $path);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($repo . '/' . $path, $content);
}

function commit(string $message): string
{
    git(['add', '.']);
    git(['commit', '-q', '-m', $message]);
    return git(['rev-parse', 'HEAD']);
}

function record(string $name, array $result, bool $shouldPass): void
{
    global $checks;
    $checks[] = [
        'case' => $name,
        'expected_pass' => $shouldPass,
        'exit' => $result['exit'],
        'passed' => ($result['exit'] === 0) === $shouldPass,
        'output' => substr($result['stdout'] . $result['stderr'], 0, 3000),
    ];
}

function check(string $name, string $input, bool $shouldPass): void
{
    global $repo;
    record($name, command(['sh', '.githooks/pre-push', 'origin', 'local-fixture'], $repo, $input), $shouldPass);
}

function refs(string $local, string $base, string $branch = 'main'): string
{
    return "refs/heads/$branch $local refs/heads/$branch $base\n";
}

function clearFixture(string $path, string $parent): void
{
    $resolved = realpath($path);
    if ($resolved === false || dirname($resolved) !== $parent
        || !preg_match('/^quanta-phpcs-test-[0-9a-f]{16}$/', basename($resolved))) {
        throw new RuntimeException('Refusing to clean an unexpected fixture path.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            if (!$entry->isLink()) {
                chmod($entry->getPathname(), 0600);
            }
            unlink($entry->getPathname());
        }
    }
    rmdir($resolved);
}

$good = <<<'PHP'
<?php

namespace Quanta;

class HookFixture
{
    public function value(): string
    {
        return 'ok';
    }
}

PHP;
$bad = "<?php\nclass bad{public function value(){return 1;}}\n";

try {
    git(['init', '-q', '-b', 'main']);
    git(['config', 'user.name', 'Local Hook Fixture']);
    git(['config', 'user.email', 'fixture@example.invalid']);
    git(['config', 'commit.gpgsign', 'false']);
    save('.gitignore', "vendor/\n");
    save('.phpcs.xml', file_get_contents($project . '/.phpcs.xml'));
    save('.githooks/pre-push', file_get_contents($hook));
    chmod($repo . '/.githooks/pre-push', 0755);
    save('src/example.php', $good);
    save('README.txt', "Fixture\n");
    $base = commit('Clean fixture base');
    command(['git', 'init', '-q', '--bare', $remote], $fixture);
    git(['remote', 'add', 'origin', $remote]);
    git(['push', '-q', 'origin', 'main']);
    git(['config', 'core.hooksPath', '.githooks']);

    $proxy = '#!/bin/sh' . "\nexec " . escapeshellarg(str_replace('\\', '/', PHP_BINARY))
        . ' ' . escapeshellarg(str_replace('\\', '/', $phpcs)) . ' "$@"' . "\n";
    save('vendor/bin/phpcs', $proxy);
    chmod($repo . '/vendor/bin/phpcs', 0755);

    // A bad pushed revision must fail even when the checked-out file is clean.
    save('src/example.php', $bad);
    $badTip = commit('Bad PHP in pushed tip');
    git(['checkout', '-q', '--detach', $base]);
    record('actual_local_push_bad_tip_clean_checkout', command(
        ['git', 'push', 'origin', $badTip . ':refs/heads/main'], $repo
    ), false);
    check('bad_non_head_tip', refs($badTip, $base), false);

    save('src/example.php', str_replace("'ok'", "'updated'", $good));
    $goodTip = commit('Correct PHP change');
    save('src/example.php', $bad);
    record('actual_local_push_good_tip_dirty_checkout', command(
        ['git', 'push', '--force', 'origin', $goodTip . ':refs/heads/main'], $repo
    ), true);
    check('good_pushed_tip_dirty_checkout', refs($goodTip, $base), true);
    git(['checkout', '--', 'src/example.php']);
    check('multiple_refs_include_bad_non_head_tip', refs($goodTip, $base, 'good') . refs($badTip, $base, 'bad'), false);

    git(['checkout', '-q', '--detach', $base]);
    save('src/style file.php', $bad);
    $spaceTip = commit('Bad PHP in space path');
    check('space_path', refs($spaceTip, $base), false);

    git(['checkout', '-q', '--detach', $base]);
    save('src/café sample.php', $bad);
    $unicodeTip = commit('Bad PHP in Unicode path');
    check('unicode_path', refs($unicodeTip, $base), false);

    git(['checkout', '-q', '--detach', $base]);
    save('src/include.inc', $bad);
    $incTip = commit('Bad PHP include');
    check('php_include_extension', refs($incTip, $base), false);

    git(['checkout', '-q', '--detach', $base]);
    save('tests/ignored.php', $bad);
    $excludedTip = commit('Existing ruleset excluded test');
    check('existing_tests_exclusion', refs($excludedTip, $base), true);

    git(['checkout', '-q', '--detach', $base]);
    save('README.txt', "Documentation-only change\n");
    $docsTip = commit('Documentation change');
    unlink($repo . '/vendor/bin/phpcs');
    check('docs_only_without_phpcs', refs($docsTip, $base), true);
    check('php_change_without_phpcs', refs($goodTip, $base), false);
    $zero = str_repeat('0', strlen($base));
    check('deleted_ref_without_phpcs', refs($zero, $base), true);
    check('no_updated_refs_without_phpcs', '', true);

    git(['checkout', '-q', '--detach', $base]);
    unlink($repo . '/src/example.php');
    $deletedTip = commit('Delete PHP file');
    check('deleted_php_path_without_phpcs', refs($deletedTip, $base), true);
    save('vendor/bin/phpcs', $proxy);
    chmod($repo . '/vendor/bin/phpcs', 0755);

    git(['checkout', '-q', '--detach', $badTip]);
    save('README.txt', "New branch tip only changes documentation\n");
    $newTip = commit('Later documentation commit');
    check('new_branch_earlier_bad_change', refs($newTip, $zero, 'new-branch'), false);
    git(['update-ref', '-d', 'refs/remotes/origin/main']);
    check('new_branch_without_tracking_checks_full_tip', refs($newTip, $zero, 'new-branch'), false);
    check('new_clean_branch_without_tracking', refs($goodTip, $zero, 'clean-new-branch'), true);
    check('missing_destination_revision', refs($goodTip, str_repeat('1', strlen($base))), false);

    // Site repositories share the existing ../../vendor and ../../.phpcs.xml.
    $mainRepo = $repo;
    $repo = $mainRepo . '/sites/fixture';
    mkdir($repo, 0700, true);
    git(['init', '-q', '-b', 'main']);
    git(['config', 'user.name', 'Local Hook Fixture']);
    git(['config', 'user.email', 'fixture@example.invalid']);
    git(['config', 'commit.gpgsign', 'false']);
    save('.githooks/pre-push', file_get_contents($hook));
    save('src/example.php', $good);
    $siteBase = commit('Clean site fixture');
    save('src/example.php', $bad);
    $siteBad = commit('Bad site PHP');
    check('site_shared_phpcs_rejects_bad_tip', refs($siteBad, $siteBase), false);
    check('site_shared_phpcs_accepts_clean_tip', refs($siteBase, str_repeat('0', strlen($siteBase))), true);
    $repo = $mainRepo;

    $failed = array_filter($checks, static fn(array $check): bool => !$check['passed']);
    echo json_encode([
        'runtime' => PHP_VERSION,
        'hook' => $hook,
        'phpcs' => $phpcs,
        'checks' => $checks,
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $status = count($failed) === 0 ? 0 : 1;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $status = 1;
} finally {
    clearFixture($fixture, $parent);
}

exit($status);
