<?php

/** Check changed outgoing blobs, not potentially different working-tree files. */
function styleCommand(array $arguments, string $input = ''): array
{
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the coding-style check.');
    }
    $offset = 0;
    while ($offset < strlen($input)) {
        $written = fwrite($pipes[0], substr($input, $offset));
        if ($written === false || $written === 0) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Cannot send the committed file to PHPCS.');
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

function styleGit(string ...$arguments): string
{
    [$code, $output, $error] = styleCommand(array_merge(['git'], $arguments));
    if ($code !== 0) {
        throw new RuntimeException('Cannot inspect the outgoing Git objects: ' . trim($error));
    }
    return $output;
}

try {
    $files = [];
    while (($line = fgets(STDIN)) !== false) {
        $update = explode(' ', trim($line));
        if (count($update) !== 4 || !preg_match('/^[a-f0-9]{40,64}$/', $update[1])) {
            throw new RuntimeException('Invalid pre-push ref input.');
        }
        [, $local, , $remote] = $update;
        if (trim($local, '0') === '') {
            continue; // Deleting a ref introduces no code.
        }
        $tip = trim(styleGit('rev-parse', '--verify', $local . '^{commit}'));
        $range = [$tip];
        if (trim($remote, '0') !== '' && styleCommand(['git', 'cat-file', '-e', $remote . '^{commit}'])[0] === 0) {
            $range[] = '^' . $remote;
        } else {
            // For a new branch, do not re-lint unchanged history already on this
            // remote. With no known remote refs, all reachable commits are new.
            $range[] = '--not';
            $range[] = '--remotes=' . ($argv[1] ?? 'origin');
        }
        $commits = trim(styleGit('rev-list', ...$range));
        if ($commits === '') {
            continue;
        }
        $changed = [];
        foreach (explode("\n", $commits) as $commit) {
            $names = styleGit('diff-tree', '--root', '-m', '--no-commit-id', '--name-only', '--diff-filter=ACMRT', '-z', '-r', $commit);
            foreach (explode("\0", $names) as $name) {
                if (preg_match('/\.(php|inc|js|css)$/i', $name)) {
                    $changed[$name] = true;
                }
            }
        }
        // Read the tip's tree once. Deleted files and symlinks are not PHP blobs.
        foreach (explode("\0", styleGit('ls-tree', '-r', '-z', '--full-tree', $tip)) as $entry) {
            if (preg_match('/^100(?:644|755) blob ([a-f0-9]+)\t(.*)$/s', $entry, $match) && isset($changed[$match[2]])) {
                $files[$match[1] . ':' . $match[2]] = [$match[1], $match[2]];
            }
        }
    }
    if ($files === []) {
        exit(0); // No-op, deletion-only or non-code-only push.
    }

    $directory = getcwd();
    $checker = null;
    foreach ([$directory . '/vendor/bin/phpcs', $directory . '/../../vendor/bin/phpcs'] as $candidate) {
        if (is_file($candidate)) {
            $checker = realpath($candidate);
            break;
        }
    }
    if ($checker === null) {
        throw new RuntimeException('PHPCS is missing. Run composer install with development dependencies in the Quanta root.');
    }
    $standard = null;
    foreach ([$directory, dirname(__DIR__)] as $base) {
        foreach (['.phpcs.xml', 'phpcs.xml', '.phpcs.xml.dist', 'phpcs.xml.dist'] as $name) {
            if (is_file($base . '/' . $name)) {
                $standard = realpath($base . '/' . $name);
                break 2;
            }
        }
    }
    if ($standard === null) {
        throw new RuntimeException('The Quanta PHPCS ruleset is missing. Restore .phpcs.xml before pushing.');
    }

    foreach ($files as [$blob, $path]) {
        $content = styleGit('cat-file', 'blob', $blob);
        [$code, $output, $error] = styleCommand([
            PHP_BINARY,
            '-d',
            'memory_limit=-1',
            $checker,
            '--standard=' . $standard,
            '--stdin-path=' . str_replace('\\', '/', $directory) . '/' . $path,
            '-',
        ], $content);
        fwrite(STDOUT, $output);
        fwrite(STDERR, $error);
        if ($code !== 0) {
            fwrite(STDERR, "PHPCS rejected committed file $path. Fix and commit it before pushing.\n");
            exit($code > 0 ? $code : 1);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'PHPCS pre-push: ' . $error->getMessage() . "\n");
    exit(1);
}
