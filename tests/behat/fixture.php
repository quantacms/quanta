<?php

/** Remove only this suite's generated tree; never follow source symlinks. */
function fixtureRemove(string $path, string $root): void
{
    if (!is_file($root . '/.quanta-behat-owned') ||
        !str_starts_with($path . '/', $root . '/') || $path === $root) {
        throw new RuntimeException('Refusing to remove a path outside the owned fixture.');
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            fixtureRemove($entry->getPathname(), $root);
        }
        rmdir($path);
    }
}

function fixtureWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write fixture: ' . $path);
    }
}

function fixtureJson(string $path, array $data): void
{
    fixtureWrite($path . '/data.json', json_encode($data, JSON_THROW_ON_ERROR));
}
