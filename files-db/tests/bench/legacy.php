<?php
/**
 * Faithful re-implementation of Quanta's legacy files-DB access patterns,
 * for parity tests and benchmarking against the quanta_db extension.
 *
 * Each method mirrors a real code path (referenced per method) — including
 * its inefficiencies and races. Do not "improve" these: the point is to
 * measure what production does today.
 */
declare(strict_types=1);

class LegacyDb
{
    private string $docroot;
    private string $tmp;

    /** Per-request memo, as in Environment::nodePath() static $node_paths. */
    private array $nodePaths = [];
    private array $missingNodes = [];

    public function __construct(string $docroot, string $tmp)
    {
        $this->docroot = rtrim($docroot, '/');
        $this->tmp = rtrim($tmp, '/');
        if (!is_dir($this->tmp)) {
            mkdir($this->tmp, 0755, true);
        }
    }

    /** Simulate a new HTTP request: statics reset, tmp symlink cache persists. */
    public function newRequest(): void
    {
        $this->nodePaths = [];
        $this->missingNodes = [];
    }

    /** Wipe the tmp symlink cache too (a fully cold process/deploy). */
    public function clearPathCache(): void
    {
        $this->newRequest();
        $cache = $this->tmp . '/cache';
        if (is_dir($cache)) {
            exec('rm -R ' . escapeshellarg($cache)); // Cache::clear() uses exec rm -R
        }
    }

    /** Environment::findNodePath() — the exec('find ...') hot spot. */
    private function findNodePath(string $folder): array
    {
        $findcmd = 'find ' . $this->docroot . '/ -type d -name "' . $folder
            . '" -not -path */_modules* -not -path *.git*';
        exec($findcmd, $results);
        return $results;
    }

    /** Cache::nodePathFolder() — tmp/cache/<c0>/<c1>/<c2> shard dirs. */
    private function nodePathFolder(string $name, bool $build = true): string
    {
        $folder = $this->tmp . '/cache';
        for ($i = 0; $i < 3; $i++) {
            $folder .= '/' . substr($name, $i, 1);
            if ($build && !is_dir($folder)) {
                mkdir($folder, 0755, true);
            }
        }
        return $folder;
    }

    /** Environment::nodePath() — memo -> symlink cache -> exec find. */
    public function nodePath(string $folder, bool $link = false)
    {
        if (isset($this->missingNodes[$folder])) {
            return false;
        }
        if (isset($this->nodePaths[$folder])) {
            $nodePathLink = $this->nodePaths[$folder];
            $cacheExists = true;
        } else {
            $candidate = $this->nodePathFolder($folder) . '/' . $folder;
            $nodePathLink = file_exists($candidate) ? $candidate : false;
            $cacheExists = false;
        }

        $nodePath = @readlink((string) $nodePathLink);
        if ($nodePath === false) {
            $results = $this->findNodePath($folder);
            if (empty($results)) {
                $this->missingNodes[$folder] = true;
                return false;
            }
            $found = [];
            foreach ($results as $res) {
                if (is_dir($res) && ($link ? true : !is_link($res))) {
                    $found[] = $res;
                    $nodePath = $res;
                }
            }
            if (empty($found)) {
                $this->missingNodes[$folder] = true;
                return false;
            }
        }

        if (!$cacheExists) {
            // Cache::storeNodePath(): symlink in the shard dir.
            $linkPath = $this->nodePathFolder($folder) . '/' . $folder;
            if (!is_link($linkPath)) {
                symlink($nodePath, $linkPath);
            }
            $this->nodePaths[$folder] = $linkPath;
        }
        return $nodePath;
    }

    /** Node::loadJSON() — full read + decode on every load. */
    public function get(string $name): ?array
    {
        $path = $this->nodePath($name);
        if ($path === false || !is_file($path . '/data.json')) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path . '/data.json'), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** JSONDataContainer::saveJSON() — unlocked fopen('w+') overwrite. */
    public function save(string $name, array $data): void
    {
        $path = $this->nodePath($name);
        $fh = fopen($path . '/data.json', 'w+');
        fwrite($fh, json_encode($data));
        fclose($fh);
    }

    /** NodeFactory::buildNode() — mkdir under father, then saveJSON. */
    public function create(string $name, string $father, array $data): void
    {
        $fatherPath = $this->nodePath($father);
        $path = $fatherPath . '/' . $name;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $fh = fopen($path . '/data.json', 'w+');
        fwrite($fh, json_encode($data));
        fclose($fh);
        // Freshly built nodes get their path cached like any resolved node.
        $this->nodePaths[$name] = null;
        unset($this->missingNodes[$name]);
        $linkPath = $this->nodePathFolder($name) . '/' . $name;
        if (!is_link($linkPath)) {
            symlink($path, $linkPath);
        }
        $this->nodePaths[$name] = $linkPath;
    }

    /** Environment::scanDirectory() as used by DirList (excludes DIR_INACTIVE '_'). */
    public function children(string $father, bool $includeHidden = false): array
    {
        $path = $this->nodePath($father);
        if ($path === false) {
            return [];
        }
        $out = [];
        foreach (scandir($path) as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            if (!$includeHidden && $entry[0] === '_') {
                continue;
            }
            if (!is_dir($path . '/' . $entry)) {
                continue; // data files, assets
            }
            $out[] = $entry;
        }
        sort($out);
        return $out;
    }

    /** NodeFactory::linkNodes() with if_exists=ignore. */
    public function link(string $target, string $container): void
    {
        $targetPath = $this->nodePath($target);
        $containerPath = $this->nodePath($container);
        $linkPath = $containerPath . '/' . $target;
        if (!is_link($linkPath)) {
            symlink($targetPath, $linkPath);
        }
    }

    /** NodeFactory::unlinkNodes() with if_not_exists=ignore. */
    public function unlinkNode(string $target, string $container): void
    {
        $containerPath = $this->nodePath($container);
        $linkPath = $containerPath . '/' . $target;
        if (is_link($linkPath)) {
            unlink($linkPath);
        }
    }

    /** BookingFactory::changeBookingStatus() — non-atomic unlink + link. */
    public function statusChange(string $target, string $from, string $to): void
    {
        $this->unlinkNode($target, $from);
        $this->link($target, $to);
    }

    /**
     * The DirList/qtag filtering pattern: list children, load EVERY node's
     * JSON, filter in PHP. This is how "bookings with status X" style
     * questions are answered today.
     */
    public function findWhere(string $father, string $key, $value): array
    {
        $out = [];
        foreach ($this->children($father) as $child) {
            $data = $this->get($child);
            if ($data !== null && array_key_exists($key, $data) && $data[$key] === $value) {
                $out[] = $child;
            }
        }
        return $out;
    }

    public function countWhere(string $father, string $key, $value): int
    {
        return count($this->findWhere($father, $key, $value));
    }
}
