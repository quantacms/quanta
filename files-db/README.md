# quanta_db — Files-DB PHP extension (Rust)

Native PHP extension implementing the **Files-DB API contract v1.1**
(`docs/files-db/api-contract.md`). It provides fast, concurrency-safe access to
Quanta's JSON-per-folder node database.

- **Files stay the source of truth.** A companion daemon (`qdbd`) loads the
  whole node tree — paths, fathers, children, links, and raw JSON documents —
  into a **shared-memory data segment** that every PHP worker maps read-only.
  While the daemon is healthy each read is a hash probe into that segment with
  **no filesystem access and no SQLite**. Everything derived is rebuildable with
  `QuantaDb::reindex()`, so there is **no data migration**.
- **Writes are safe**: tmp-file + atomic `rename()` (readers never see a partial
  document), per-node `flock` (crash of the holder auto-releases), `mkdir` as
  atomic name reservation, `rename()` of symlinks for atomic status changes
  (`QuantaDb::relink`). Each write is pushed to `qdbd` over a unix socket; the
  daemon acks only after publishing to shared memory, so reads-your-writes holds
  across processes (contract §4.1).
- **Reads are fast**: name→path resolution is a shared-memory lookup instead of
  `find` over the docroot; document bytes come straight from the segment
  (`getRaw()` returns them as a string for PHP-side `json_decode`); `find`/`count`
  scan the in-memory records instead of recursive directory scans.
- **Fallback, never wedged**: if `qdbd` is down, stale, or still starting, the
  extension detects the missing heartbeat and serves from a per-process
  filesystem walk snapshot + direct file reads — exactly the legacy behavior,
  minus the daemon. The app is always correct; the daemon only removes work.

## API — class `QuantaDb` (static methods)

See `docs/files-db/quanta_db.stub.php` for the full signatures:
`QuantaDb::get/getRaw/path/exists/meta/children/links/find/count/put/update/
delete/link/unlink/relink/reindex/stats/coherent/version` + the
`QuantaDbException` class.

## Configuration (php.ini, env fallback `QUANTA_DB_*`)

| Key | Default | |
|---|---|---|
| `quanta_db.root` | *(required)* | Data root (the site's `db/` directory) |
| `quanta_db.shm_dir` | `/dev/shm/quanta_db/<hash>` | Data segments (`data.<epoch>.shm`); tmpfs preferred, falls back to `<tmp>/quanta_db/<hash>` |
| `quanta_db.socket_path` | `<tmp>/quanta_db/<hash>/qdbd.sock` | `qdbd` write-notification socket |
| `quanta_db.lock_dir` | `<tmp>/quanta_db/<hash>/locks` | Per-node lock files |
| `quanta_db.trashbin_dir` | `<tmp>/quanta_db/<hash>/trashbin` | `QuantaDb::delete` destination |
| `quanta_db.lock_timeout_ms` | `5000` | Lock wait budget |
| `quanta_db.write_ack_timeout_ms` | `250` | Budget for a `qdbd` write ack before poisoning coherence and returning (the write is already on disk) |
| `quanta_db.shm_size_mb` | `64` | Initial per-epoch segment budget (sparse on tmpfs; grown by compaction) |
| `quanta_db.verify_reads` | `always` | Fallback-mode only: `always` stats files on read; `never` trusts the snapshot |
| `quanta_db.neg_cache_ms` | `30000` | Fallback-mode TTL for the per-process known-absent cache; `0` disables |
| `quanta_db.metrics` | `on` | Shared-memory control plane + counters (heartbeat, active epoch, `qdbstat`); `off` forces permanent fallback |
| `quanta_db.metrics_path` | `<tmp>/quanta_db/<hash>/metrics.shm` | Control/counter arena (`MAP_SHARED`) |

Config is resolved once per PHP process at first use (`ini_set` before the first
call — including `QuantaDb::coherent()` — works; after it, it doesn't).

## The daemon — `qdbd`

`qdbd` is a per-pod process (started by the entrypoint unless
`QUANTA_DB_DAEMON=off`) and the **single writer** of the shared-memory segment:

1. **Boot**: add inotify watches (watch-before-scan), walk the docroot reading
   every document, project the tree into a fresh segment, and publish its epoch
   + a coherence heartbeat into `metrics.shm`.
2. **Serve**: a poll loop over {inotify, the unix-socket listener, clients}.
   - API writes arrive over the socket → the daemon re-reads the affected node
     from disk (files are the truth), publishes to shared memory, then acks.
   - Out-of-band changes (legacy writers, another replica on a shared volume)
     arrive via inotify — directory create/delete/move **and** `data*.json`
     content events — republishing affected records within its latency. A
     presence+drift **reconcile** runs on a timer (default 60 s) as a safety
     net; an inotify queue overflow forces an immediate resync; hitting the
     kernel watch limit (`fs.inotify.max_user_watches`) degrades to
     reconcile-only.
3. **Growth**: records are immutable and appended; an update swaps a slot to the
   new record atomically. When the arena fills or dead bytes pile up, the daemon
   writes a fresh segment file (next epoch) from its in-RAM model and flips the
   published epoch — readers remap on their next call and the predecessor file
   is kept until the following compaction so an in-flight reader never faults.

While the heartbeat is fresh the extension treats the segment as authoritative:
a lookup miss is a *definitive absence*, answered with no walk
(`QuantaDb::coherent()` returns true, and the Quanta `nodePath` shim skips its
legacy `find` for such names). On a stale/missing heartbeat the extension flips
to fallback and the shim resumes its legacy `find` — the pre-daemon behavior.

```bash
qdbd                 # started by the entrypoint (unless QUANTA_DB_DAEMON=off)
qdbd --once          # load + publish one snapshot, print status, exit
qdbd -v              # log every applied event
qdbstat --once       # shows the daemon line: COHERENT / STALE / none
```

## Monitoring — `qdbstat` (varnishstat-style)

Every worker bumps atomic counters in a small `MAP_SHARED` arena
(`metrics.shm`); `qdbstat` maps it read-only and reports live op rates, cache
hit ratio, average access time, lock contention, daemon coherence + active
epoch, and — from the data segment — node/link cardinality, document bytes, and
arena usage. Counters are also exposed in `QuantaDb::stats()`.

```bash
qdbstat            # live, auto-refreshing (default 1s); Ctrl-C to quit
qdbstat --once     # one snapshot and exit
qdbstat --json     # one JSON snapshot (for scripting)
qdbstat -n 2       # refresh every 2s
```

It locates the arena + segment by deriving the same per-root data dir the
extension uses, so it needs the data root: `--root <path>` or `$QUANTA_DB_ROOT`
(set in the app image), or explicit `--shm` / `--data-dir` overrides. The app
image ships it at `/usr/local/bin/qdbstat`:

```bash
kubectl exec <pod> -- qdbstat --once
```

**Per-pod:** the arena + segment live under the pod's tmp/tmpfs dirs, so
`qdbstat` reports the pod it runs in — there is no cluster-aggregated view.
When `quanta_db.metrics=off` the control plane is never created and the
extension runs in permanent fallback mode.

## Build & test (Docker)

```bash
# Build the extension AND run the conformance suite (fails the build on red):
docker build -t quanta-db quanta/files-db/

# Extract the compiled quanta_db.so:
docker build --target artifact -o quanta/files-db/dist quanta/files-db/
```

Built against `php:8.2-apache` so the `.so` matches the production image ABI.
Load it with `extension=/path/to/quanta_db.so`.

The conformance suite runs in **two modes** (see `tests/run-tests.sh`):
`fallback` (no daemon) and `daemon` (a `qdbd` is spawned per test root and the
suite waits for coherence). Both must stay green. Rust unit tests
(`cargo test`) cover the segment layout, reader/writer, tombstones, compaction,
and a reader-under-churn torn-read stress test.

### Legacy parity tests + benchmark

`tests/bench/legacy.php` re-implements the legacy access patterns verbatim
(`Environment::nodePath()`'s `exec find` + tmp symlink cache, `saveJSON()`'s
unlocked `fopen('w+')`, `linkNodes`/`unlinkNodes`, the DirList load-every-JSON
filter). `tests/bench/bench.php` first asserts both give the same answers on one
shared tree, then times identical operations:

```bash
docker run --rm quanta-db sh /ext/tests/run-bench.sh
# sizing: -e QDB_BENCH_N=1000 -e QDB_BENCH_WRITES=500 ...
```

The extension's decisive win is **path resolution** (a shared-memory lookup vs
`exec find` over the docroot) and **write coordination** (atomic put + lock);
writes pay the deliberate durability cost (fsync + atomic rename + lock). CMS
traffic is overwhelmingly reads.

### Demo page

`demo/index.html` is a self-contained animated before/after (lookup race,
torn-write vs atomic-rename, measured benchmark bars) plus a **live race** that
times `exec(find)` vs `QuantaDb::path()` on a random real node of the mounted
tree (`demo/live.php`). The main vhost intentionally blocks `.php` under the
site dir, so serve it with PHP's built-in server in the container:

```bash
docker compose run --rm -p 8090:8090 --entrypoint php web \
  -S 0.0.0.0:8090 -t /var/www/quanta/sites/localhost/quanta/files-db/demo
# open http://localhost:8090
```

### Local dev loop

```bash
docker build --target builder-base -t quanta-db-builder quanta/files-db/
docker run -d --name qdb-build \
  -v "$PWD/quanta/files-db:/ext" -v qdb-cargo:/opt/cargo/registry \
  -v qdb-target:/target -e CARGO_TARGET_DIR=/target -w /ext \
  quanta-db-builder sleep infinity
docker exec qdb-build cargo build --release
docker exec -e QDB_EXT=/target/release/libquanta_db.so \
  -e QDBD_BIN=/target/release/qdbd qdb-build sh tests/run-tests.sh
```

## Semantics notes (beyond the contract)

- Directory names `files`, `assets`, `.git`, `_modules` and `.`-prefixed dirs
  are never treated as nodes (mirrors Quanta's `scanDirectory()` and
  `findNodePath()` exclusions); the configured shm/socket/lock/trashbin dirs are
  skipped if placed inside the root.
- With a coherent daemon a lookup miss is a definitive absence (the daemon's
  inotify/reconcile already reflects any out-of-band create). In **fallback
  mode** a miss triggers a self-heal walk of the root (~ms, tree-size
  dependent); a proven-absent verdict is then cached per process for
  `quanta_db.neg_cache_ms` (default 30 s) so a genuinely-missing name (e.g. a
  field the app mistakes for a node) does not re-walk on every request. A `put`
  or `reindex` clears the verdict; and because a stale "absent" only means the
  extension returns NULL, the Quanta shim's legacy `find` still locates a node
  created out of band. `qdbstat` shows these as `negcache` hits.
- `children()` hides `_`-prefixed names by default (Quanta's DIR_INACTIVE
  convention); `find()` criteria do not hide them. The daemon stores the exact
  `read_dir` child list (including out-of-root symlink targets), so daemon-mode
  `children()`/`links()` are byte-identical to the filesystem.
- `getRaw()` returns the raw JSON string; the extension's read win is serving
  those bytes from shared memory with no syscall, leaving the decode to PHP's
  fast native `json_decode`.
- Duplicate node names: first directory found wins (legacy behavior is a
  warning); keep names globally unique as Quanta requires.

## Non-goals in v1

Node move/rename, language fallback (stays in `NodeFactory`), multi-root per
process, range/prefix `where` operators, and a cluster-aggregated `qdbstat`
view (the segment + arena are per-pod).
