# quanta_db — Files-DB PHP extension (Rust)

Native PHP extension implementing the **Files-DB API contract v1**
(`docs/files-db/api-contract.md`). It provides fast, concurrency-safe access
to Quanta's JSON-per-folder node database.

- **Files stay the source of truth.** The extension maintains a derived
  SQLite index (embedded via rusqlite's bundled SQLite — no PHP sqlite
  extension needed) plus a per-process decoded-document cache validated by
  per-node generation counters. Everything derived is rebuildable with
  `QuantaDb::reindex()`, so there is **no data migration**.
- **Writes are safe**: tmp-file + atomic `rename()` (readers never see a
  partial document), per-node `flock` (crash of the holder auto-releases),
  `mkdir` as atomic name reservation, `rename()` of symlinks for atomic
  status changes (`QuantaDb::relink`).
- **Reads are fast**: name→path resolution is an indexed lookup instead of
  `find` over the docroot; `find`/`count` answer booking-dashboard style
  queries from the index instead of recursive directory scans.

## API — class `QuantaDb` (static methods)

See `docs/files-db/quanta_db.stub.php` for the full signatures:
`QuantaDb::get/path/exists/meta/children/links/find/count/put/update/delete/
link/unlink/relink/reindex/stats/version` + the `QuantaDbException` class.

## Configuration (php.ini, env fallback `QUANTA_DB_*`)

| Key | Default | |
|---|---|---|
| `quanta_db.root` | *(required)* | Data root (the site's `db/` directory) |
| `quanta_db.index_path` | `<tmp>/quanta_db/<hash>/index.sqlite` | Derived index (never on NFS) |
| `quanta_db.lock_dir` | `<tmp>/quanta_db/<hash>/locks` | Per-node lock files |
| `quanta_db.trashbin_dir` | `<tmp>/quanta_db/<hash>/trashbin` | `QuantaDb::delete` destination |
| `quanta_db.lock_timeout_ms` | `5000` | Lock wait budget |
| `quanta_db.verify_reads` | `always` | `always` stats files on read; `never` trusts the index |
| `quanta_db.neg_cache_ms` | `30000` | TTL (ms) for the per-process known-absent cache; a miss within it skips the full-docroot self-heal walk. `0` disables |
| `quanta_db.metrics` | `on` | Shared-memory counters for `qdbstat`; set `off` to disable |
| `quanta_db.metrics_path` | `<tmp>/quanta_db/<hash>/metrics.shm` | Counter arena (`MAP_SHARED`) |
| `quanta_db.watch` / `QUANTA_DB_WATCH` | `on` | Whether the entrypoint starts the `qdbwatch` daemon. The extension trusts the index only while the watcher heartbeat is fresh, so this is effectively read by the entrypoint |

Config is resolved once per PHP process at first use (`ini_set` before the
first call works; after it, it doesn't).

## Real-time authoritative index — `qdbwatch`

A lookup miss normally costs a self-heal walk of the whole docroot (see the
Semantics note below), because the SQLite index is *derived* — a legacy writer
could create a node the index never learned about. `qdbwatch` removes that cost:
a per-pod daemon that watches the docroot with **inotify** and applies
create/delete/move events to the index within milliseconds, publishing a
**heartbeat + coherence flag** into `metrics.shm`.

While that flag is fresh the extension treats the index as authoritative: an
index miss is a *definitive absence*, answered with no walk (`QuantaDb::coherent()`
returns true, and the HILI `nodePath` shim skips its legacy `find` for such
names — so a never-existing name costs microseconds in every layer). A full
**presence-only resync** runs on a timer (default 60 s) as a safety net; an
inotify queue overflow forces an immediate resync; hitting the kernel watch
limit (`fs.inotify.max_user_watches`) degrades to resync-only.

**Fail-safe:** if the watcher is disabled, crashes, or is still starting, the
heartbeat goes stale and the extension automatically falls back to the
`fs_search` walk + negative cache, and the shim resumes its legacy `find` — i.e.
exactly the behavior without a watcher. The app is always correct; the watcher
only removes work.

```bash
qdbwatch                 # started by the entrypoint (unless QUANTA_DB_WATCH=off)
qdbwatch --once          # snapshot + establish coherence, print status, exit
qdbwatch -v              # log every applied event
qdbstat --once           # shows the watcher line: COHERENT / STALE / none
```

The watcher is per-pod (like the index), so each pod runs its own.

## Monitoring — `qdbstat` (varnishstat-style)

Every worker process bumps atomic counters in a small `MAP_SHARED` arena
(`metrics.shm`, alongside the index); the `qdbstat` binary maps it read-only
and reports live op rates, cache hit ratio, average access time, lock
contention, and — from the SQLite index — node/link/doc counts and total
document bytes. Counters are also exposed in `QuantaDb::stats()`.

```bash
qdbstat            # live, auto-refreshing (default 1s); Ctrl-C to quit
qdbstat --once     # one snapshot and exit
qdbstat --json     # one JSON snapshot (for scripting)
qdbstat -n 2       # refresh every 2s
```

It locates the arena + index by deriving the same per-root data dir the
extension uses, so it needs the data root: `--root <path>` or `$QUANTA_DB_ROOT`
(set in the app image), or explicit `--shm` / `--index` overrides. The app
image ships it at `/usr/local/bin/qdbstat`:

```bash
kubectl exec <pod> -- qdbstat --once
```

**Per-pod:** the arena lives under the pod's tmp dir (like the index), so
`qdbstat` reports the pod it runs in — there is no cluster-aggregated view.
When `quanta_db.metrics=off` the arena is never created and `qdbstat` falls
back to index-only stats.

## Build & test (Docker)

```bash
# Build the extension AND run the conformance suite (fails the build on red):
docker build -t quanta-db quanta/files-db/

# Extract the compiled quanta_db.so:
docker build --target artifact -o quanta/files-db/dist quanta/files-db/
```

Built against `php:8.2-apache` so the `.so` matches the production image ABI.
Load it with `extension=/path/to/quanta_db.so`.

### Legacy parity tests + benchmark

`tests/bench/legacy.php` re-implements the legacy access patterns verbatim
(`Environment::nodePath()`'s `exec find` + tmp symlink cache, `saveJSON()`'s
unlocked `fopen('w+')`, `linkNodes`/`unlinkNodes`, the DirList
load-every-JSON filter). `tests/bench/bench.php` first asserts both give the
same answers on one shared tree, then times identical operations:

```bash
docker run --rm quanta-db sh /ext/tests/run-bench.sh
# sizing: -e QDB_BENCH_N=1000 -e QDB_BENCH_WRITES=500 ...
```

Representative results (400 bookings + 400 businesses, in-container):
cold name→path resolution **~1700× faster** (6µs vs 11ms `exec find` — and
the production docroot is much larger than the bench tree, so the real gap
is bigger); warm path lookups ~3×; children listing ~8×; filtered
find/count ~2.5×; distinct-document reads ~2×; relink ≈ parity. Writes are
the deliberate trade: legacy's unlocked, non-durable `fwrite` is ~30µs while
`QuantaDb::put` pays ~1ms for fsync + atomic rename + locking + index
consistency (creates ~10ms). CMS traffic is overwhelmingly reads.

### Demo page

`demo/index.html` is a self-contained animated before/after (lookup race,
torn-write vs atomic-rename, measured benchmark bars) plus a **live race**
that times `exec(find)` vs `QuantaDb::path()` on a random real node of the
mounted tree (`demo/live.php`). The main vhost intentionally blocks `.php`
under the site dir, so serve it with PHP's built-in server in the container:

```bash
docker compose run --rm -p 8090:8090 --entrypoint php web \
  -S 0.0.0.0:8090 -t /var/www/quanta/sites/localhost/quanta/files-db/demo
# open http://localhost:8090
```

(Opening `demo/index.html` straight from disk also works — everything except
the live race, which needs the endpoint.)

### Local dev loop

```bash
docker build --target builder-base -t quanta-db-builder quanta/files-db/
docker run -d --name qdb-build \
  -v "$PWD/quanta/files-db:/ext" -v qdb-cargo:/opt/cargo/registry \
  -v qdb-target:/target -e CARGO_TARGET_DIR=/target -w /ext \
  quanta-db-builder sleep infinity
docker exec qdb-build cargo build --release
docker exec -e QDB_EXT=/target/release/libquanta_db.so qdb-build sh tests/run-tests.sh
```

## Semantics notes (beyond the contract)

- Directory names `files`, `assets`, `.git`, `_modules` and `.`-prefixed dirs
  are never treated as nodes (mirrors Quanta's `scanDirectory()` and
  `findNodePath()` exclusions); the configured index/lock/trashbin dirs are
  skipped if placed inside the root.
- A lookup miss (name not in the index) triggers a self-heal walk of the root
  (~ms, tree-size dependent) so nodes created by legacy writers are found.
  To stop a genuinely-missing name (e.g. a field the app mistakes for a node)
  from re-walking the whole docroot on every request, a proven-absent verdict
  is cached per process for `quanta_db.neg_cache_ms` (default 30s): within that
  window the miss is answered without walking, so the walk happens at most once
  per name per worker per TTL. A `put` that creates the name, or a `reindex`,
  clears the verdict; and because a stale "absent" only means the extension
  returns NULL, the HILI shim's legacy `find` still locates a node created out
  of band. `qdbstat` shows these as `negcache` hits.
- `children()` hides `_`-prefixed names by default (Quanta's DIR_INACTIVE
  convention); `find()` criteria do not hide them.
- `lineage` and `name_prefix` criteria answer from the index — run
  `QuantaDb::reindex()` once after deploying onto an existing tree.
  `father`/`in` criteria read the filesystem directly and need no warm-up.
- Duplicate node names: first directory found wins (legacy behavior is a
  warning); keep names globally unique as Quanta requires.

## Non-goals in v1

Node move/rename, language fallback (stays in `NodeFactory`), multi-root per
process, range/prefix `where` operators, shm immutable-array cache and the
inotify watcher (extension v2 — the API is designed so they slot in without
call-site changes).
