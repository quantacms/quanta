<?php

declare(strict_types=1);

/** Check committed PHP content without changing the index or working tree. */
function runCommand(array $command, ?string $directory = null): string
{
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $directory);
    if ($process === false) {
        throw new RuntimeException('Cannot start ' . $command[0]);
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(trim($error) ?: 'Command failed: ' . $command[0]);
    }
    return $output;
}

function removeSnapshot(string $directory): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function changedFiles(string $remote, string $old, string $new): array
{
    if (trim($old, '0') !== '') {
        // An advertised commit need not exist locally (for example after a fetch
        // from another machine). Refuse rather than silently skip its changes.
        try {
            runCommand(['git', 'cat-file', '-e', $old . '^{commit}']);
        } catch (RuntimeException $error) {
            throw new RuntimeException(
                'Fetch the remote before pushing: the previous commit is not available locally.'
            );
        }
        $paths = runCommand(['git', 'diff', '--name-only', '--diff-filter=ACMRT', '-z', $old, $new, '--']);
    } else {
        // A first push to a fork may have only upstream tracking refs. Use
        // those as the baseline when the destination has not been fetched yet.
        $tracking = trim(runCommand(['git', 'for-each-ref', '--format=%(refname)', 'refs/remotes/' . $remote . '/']));
        $exclude = $tracking === '' ? '--remotes' : '--remotes=' . $remote;
        $commits = trim(runCommand(['git', 'rev-list', $new, '--not', $exclude]));
        $paths = '';
        foreach ($commits === '' ? [] : explode("\n", $commits) as $commit) {
            $paths .= runCommand([
                'git', 'diff-tree', '--root', '-m', '--no-commit-id', '--name-only',
                '--diff-filter=ACMRT', '-r', '-z', $commit, '--',
            ]);
        }
    }

    return array_values(array_unique(array_filter(explode("\0", $paths), static function (string $path): bool {
        return preg_match('/\.(php|inc)$/i', $path) === 1;
    })));
}

try {
    $project = dirname(__DIR__);
    $repository = trim(runCommand(['git', 'rev-parse', '--show-toplevel']));
    chdir($repository);
    $remote = $argv[1] ?? 'origin';
    $updates = [];
    while (($line = fgets(STDIN)) !== false) {
        $fields = preg_split('/\s+/', trim($line));
        $objectId = '/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/';
        if (
            count($fields) !== 4
            || !preg_match($objectId, $fields[1])
            || !preg_match($objectId, $fields[3])
        ) {
            throw new RuntimeException('Invalid pre-push input from Git.');
        }
        [, $new, $reference, $old] = $fields;
        if (trim($new, '0') === '') {
            continue; // Deleting a ref introduces no code to check.
        }
        $updates[] = [$new, $old, $reference];
    }

    foreach ($updates as [$new, $old, $reference]) {
        $files = changedFiles($remote, $old, $new);
        if ($files === []) {
            continue;
        }
        $phpcs = $project . '/vendor/bin/phpcs';
        if (!is_file($phpcs)) {
            throw new RuntimeException(
                'PHP_CodeSniffer is missing. Run composer install (including require-dev) in ' . $project . '.'
            );
        }

        $snapshot = sys_get_temp_dir() . '/quanta-style-' . bin2hex(random_bytes(12));
        if (!mkdir($snapshot, 0700)) {
            throw new RuntimeException('Cannot create the style-check snapshot.');
        }
        register_shutdown_function(static function () use ($snapshot): void {
            removeSnapshot($snapshot);
        });
        $targets = [];
        foreach ($files as $file) {
            // A file changed earlier on a new branch may be deleted at its tip.
            $entry = runCommand(['git', '--literal-pathspecs', 'ls-tree', '-z', $new, '--', $file]);
            if (!preg_match('/^100[0-7]{3} blob ([0-9a-f]+)\t/', $entry, $match)) {
                continue; // Do not follow committed symlinks or submodules.
            }
            $target = $snapshot . '/' . $file;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            file_put_contents($target, runCommand(['git', 'cat-file', 'blob', $match[1]]));
            $targets[] = $target;
        }
        if ($targets === []) {
            continue;
        }

        fwrite(STDERR, 'Checking committed PHP style for ' . $reference . "...\n");
        $command = [
            PHP_BINARY, $phpcs, '--standard=' . $project . '/.phpcs.xml',
            '--extensions=php,inc', '--basepath=' . $snapshot,
        ];
        // Small argument batches avoid command-line limits. Absolute paths and
        // array arguments also preserve spaces, newlines and option-like names.
        foreach (array_chunk($targets, 32) as $batch) {
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR];
            $process = proc_open([...$command, ...$batch], $descriptors, $pipes, $project);
            if ($process === false || proc_close($process) !== 0) {
                throw new RuntimeException(
                    'Push blocked by PHP_CodeSniffer. Fix the reported files and commit the changes.'
                );
            }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Quanta style check: ' . $error->getMessage() . "\n");
    exit(1);
}
