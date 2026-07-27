# How the Files-DB extension works (big picture)

Quanta stores every node as a **directory with `data*.json` inside it**. That is
the database, and it stays the database. The `quanta_db` extension does not
replace it — it removes the *shelling out* Quanta used to do to locate nodes,
and makes writes atomic.

## The problem

Legacy Quanta answers "where is node X?" with `exec('find …')` over the docroot,
"which user has this email?" with `exec('grep -r …')`, and writes documents with
an unlocked `fopen('w+')` (a reader can observe a half-written file). On a large
tree under many PHP workers, those subprocess spawns dominate a request.

Reading a *known* document was never the problem — see
[What is deliberately NOT routed through it](#what-is-deliberately-not-routed-through-it).

## The three parts

```
       files on disk  (source of truth)
              |                     ^
      reads   |                     | writes: tmp file + atomic rename, flock
              v                     |
        +-----------+   publishes   +--------------------------+
        |   qdbd    | ------------> |  shared-memory segment   |
        | (daemon)  |               |  data.<epoch>.shm        |
        +-----------+               +--------------------------+
           ^     ^                        |  mmap read-only
           |     |                        v
      inotify   unix socket        +---------------------+
      (external  (write notify)    | PHP workers         |
       changes)  <---------------- | quanta_db ext       |
                                   | QuantaDb::path(...) |
                                   +---------------------+
```

(The app reaches for `path()`, `find()`, `put()` and `link()`. It does *not*
read documents through the extension — see below.)

1. **`qdbd` — one daemon per pod, the single writer.**
   On boot it walks the whole docroot and projects the tree — names → paths,
   father/children, symlink containers, and the raw JSON bytes — into one
   mmap'd segment file. Then it keeps that segment true: inotify for changes
   made outside the API, a periodic reconcile as a safety net, and a unix
   socket where the extension announces its own writes.

2. **The shared-memory segment — the lookup path.**
   Every PHP worker maps it **read-only**. A lookup is a hash probe plus a
   bounds-checked byte copy: no `find`, no `open()`, no SQLite. Records are
   immutable and append-only; an update writes a new record and flips one slot
   pointer with a release store, so readers never need a lock and never see a
   half-written record. Growth/compaction writes a *new* epoch file and flips
   the published epoch — the old file stays mapped and valid for anyone still
   reading it.

3. **The PHP extension — `QuantaDb::*`.**
   Static methods (`get`, `getRaw`, `path`, `children`, `links`, `find`,
   `put`, `update`, `delete`, `link`/`unlink`/`relink`, …) defined by
   [`api-contract.md`](api-contract.md). Call sites never know whether they are
   talking to this extension or the pure-PHP polyfill.

## Writes

Writes always go to disk first, through the safe primitives: write a temp file
then `rename()` (readers never observe a partial document), `flock` per node,
`mkdir` as atomic name reservation, `rename()` of symlinks for status changes.
The extension then notifies `qdbd` over the socket and waits for its ack, which
comes only after the change is visible in shared memory — that is what makes
**read-your-writes** hold across separate PHP processes.

## Why it can't wedge

`qdbd` publishes a heartbeat into a small control arena.

- **Heartbeat fresh** → the segment is authoritative. A lookup miss means the
  node really does not exist, and the answer is returned without touching disk.
- **Heartbeat stale/missing** (daemon down, restarting, or `metrics=off`) → the
  extension silently falls back to a per-process filesystem walk + direct file
  reads: exactly the legacy behavior, just slower. `QuantaDb::coherent()`
  reports which mode is active.

Because everything in shared memory is *derived* from the files, it is always
rebuildable (`QuantaDb::reindex()`, or simply restarting `qdbd`). There is no
migration, no separate schema, and nothing to lose if the segment is thrown
away.

## How Quanta actually uses it

The contract (`api-contract.md`) is broader than what the app calls. The
extension was **layered into the existing code, not swapped for it** — every
legacy path is still present, both as the front-line cache and as the fallback.
These are the wired-up call sites:

| Call site | Uses | Replaces |
|---|---|---|
| `Environment::nodePath()` | `QuantaDb::path()` + `coherent()` | `exec('find …')` |
| `JSONDataContainer::saveJSON()` | `QuantaDb::put()` | unlocked `fopen('w+')` |
| `NodeFactory::linkNodes/unlinkNodes` | `QuantaDb::link()` / `unlink()` | manual `symlink`/`unlink` |
| `UserFactory::getUserFromField()` | `QuantaDb::find()` | `exec('grep -r …')` |
| `FastDirList` | `QuantaDb::path()` | its own `nodePath()` bypass |

Every one of them is guarded by `class_exists('QuantaDb')` inside a
`try/catch`, and **falls through to the legacy implementation** on absence,
error, or an unexpected result. The extension is never load-bearing.

That means there are *two* independent fallbacks stacked: the extension's own
(shared memory → per-process filesystem walk, when the daemon is stale) and the
app's (extension → original PHP code, when the class is missing or throws).

### Path resolution is a fourth tier, not a replacement

`Environment::nodePath()` keeps its whole legacy chain and consults the
extension only when that chain misses:

```
static $node_paths  →  tmp/cache shard symlink  →  QuantaDb::path()  →  exec find
```

The extension's answer is written *back into* the shard symlink cache, so it
feeds the old layer rather than bypassing it. Its job is to replace the last
step. It also lets a *definitive absence* skip the `find` entirely: when
`QuantaDb::coherent()` is true, a null lookup means the node genuinely does not
exist. Lookups with `$link = TRUE` skip the extension and use the legacy `find`.

> **Known gap.** The persistent `__MISSING__` negative marker in the shard cache
> is checked *before* the extension is consulted and has no TTL, so a node
> created out-of-band (another replica, a legacy writer) can stay invisible to a
> pod even though the daemon has already seen it. This is pre-existing cache
> behaviour, not something the extension introduced — but the extension does not
> currently fix it either.

### What is deliberately NOT routed through it

**Document reads.** `Node::loadJSON()` intentionally keeps `file_get_contents`
+ `json_decode`. Benchmarked on-pod, `QuantaDb::get()` was **~2–4× slower** for
these nodes: `data.json` files are tiny and already hot in the OS page cache
(~20 µs), while the extension adds an FFI crossing, a `verify_reads` stat, and
rebuilding the PHP value tree from a serde intermediate (~55–120 µs).
`loadJSON` is the hottest function on admin list pages (~26 % of render
self-time when wired through the extension), so the cheap path stays.

The lesson generalises: shared memory wins where legacy had to **search**
(spawn a process, walk a tree) or **coordinate** (lock, publish atomically). It
does not beat a single `read()` of a hot small file.

## Performance

The gain is structural, not micro-optimisation — but only on the paths that are
actually wired up.

| Operation | Legacy cost | Now |
|---|---|---|
| Resolve name → path (cache miss) | `exec find` over the docroot — process spawn + full tree walk | one hash probe in shared memory |
| Resolve name → path (cache hit) | static array / shard symlink | **unchanged** — extension not consulted |
| User lookup by field | `exec grep -r` over `_users` | in-memory `find` with `where` |
| Write a document | unlocked `fopen('w+')`, torn reads possible | `flock` + temp file + atomic `rename` + daemon ack |
| Link / unlink | `symlink()` + separate bookkeeping | one atomic API call |
| Read a known document | `open` + `read` + `json_decode` | **unchanged** — deliberately still the legacy read |

The decisive win is **path resolution on a cold name**: it goes from "walk the
tree" to "probe a hash", so it stops scaling with the size of the site. Since
most requests touch some cold names, that is where the request-level gain comes
from — not from reads.

**Writes deliberately get slower.** The lock, the temp-file + `rename`, and
waiting for the daemon's ack are the price of atomicity and read-your-writes;
legacy's faster write is faster because it is unsafe. The ack has a budget
(`write_ack_timeout_ms`, default 250 ms) — if the daemon does not answer in
time the write is already on disk, coherence is marked poisoned, and the
extension keeps going in fallback mode.

**In fallback mode** (daemon down) the app is simply back to the legacy profile.
Nothing is faster, nothing breaks.

Measure rather than trusting numbers in a doc — the ratios depend heavily on
tree size and document size. Note the bench compares the *API* against legacy
patterns, so it also times paths the app does not use (reads):

```bash
docker run --rm quanta-db sh /ext/tests/run-bench.sh
# sizing: -e QDB_BENCH_N=1000 -e QDB_BENCH_WRITES=500
```

`tests/bench/bench.php` re-implements the legacy access patterns verbatim,
asserts both paths give identical answers on one shared tree, then times the
same operations side by side (cold/warm path lookup, distinct + repeated reads,
`put`/`update`/create, `children`, `find`/`count`, `relink`) and prints the
per-op time and speedup for each. `qdbstat` gives the same picture on live
traffic: op rates, hit ratio, average access time, lock contention.

## Observability

Workers bump atomic counters in the same control arena. `qdbstat` maps it
read-only and shows op rates, hit ratio, access times, lock contention, daemon
coherence and epoch, plus segment cardinality and arena usage — per pod
(`kubectl exec <pod> -- qdbstat --once`).
