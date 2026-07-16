# Files-DB API Contract — `quanta_db` (v1.1)

Status: DRAFT — normative once the first implementation ships.
Contract version: `1.1`. Implementations report it via `QuantaDb::version()`.

This document defines the single API through which Quanta code accesses
the file-based node database. It is written so that two implementations can
coexist and be swapped freely:

- **Polyfill** — pure PHP (`_modules/quanta_db/`), always available. Uses an
  SQLite index + `flock` + atomic renames.
- **Extension** — native (Rust Zend extension), loaded via `php.ini`. A
  companion daemon (`qdbd`) loads the whole tree into a shared-memory segment
  that the extension maps read-only, keeps it authoritative with an inotify
  watcher + periodic reconcile, and receives write notifications over a unix
  socket; when the daemon is down the extension serves from a per-process walk
  snapshot. There is no SQLite in the extension.

**v1.1 additions:** `QuantaDb::getRaw()` (raw-JSON-string read); config keys
`quanta_db.shm_dir`, `quanta_db.socket_path`, `quanta_db.write_ack_timeout_ms`,
`quanta_db.shm_size_mb` replace the extension's `index_path`. All v1.0
signatures are unchanged.

Call sites never know which one they are talking to. The dispatch rule is:
if `extension_loaded('quanta_db')`, the native functions exist and the
polyfill MUST NOT redefine them; otherwise the polyfill defines the same
functions in PHP. Both MUST pass the same conformance suite (§10).

---

## 1. Design rules (normative)

1. **Files are the single source of truth.** A node is a directory containing
   `data.json` (language-neutral) and/or `data_<lang>.json`. Anything else an
   implementation maintains (SQLite file, shm index, caches) is *derived data*
   that MUST be rebuildable from the files alone (`QuantaDb::reindex()`).
   There is no data migration in either direction.
2. **Arrays at the boundary.** Node data crosses the API as PHP arrays — the
   exact equivalent of `json_decode($raw, true)`. Never objects. Returned
   arrays MUST be treated as immutable by callers: an implementation MAY
   return a shared/copy-on-write array, so mutating a returned array MUST
   never affect the store or other callers. All writes go through
   `QuantaDb::put()` / `QuantaDb::update()`.
3. **Node identity = globally unique directory name** (unchanged from Quanta).
   The API resolves names to paths; callers never build paths themselves.
4. **Same behavior, different speed.** The API preserves current on-disk
   semantics: directory hierarchy as parent relation, symlinks for
   categories/statuses, trashbin on delete. A site produced through this API
   is byte-compatible with one produced by legacy code (modulo JSON key
   order and whitespace).

## 2. Terminology and data model

| Term | Meaning |
|---|---|
| node | A directory under the configured root; identified by its basename. |
| data document | Decoded content of one `data[_<lang>].json` file, as array. |
| father | The node whose directory directly contains this node's directory. |
| container / link | A symlink inside a container node's dir pointing to a target node's dir (categories, booking statuses). |
| meta | Implementation-derived facts about a node (path, mtime, generation…). |
| generation | Monotonic per-node counter, bumped by every successful write through the API. Authoritative cache invalidator. |

Reserved: top-level JSON keys starting with `_` are reserved for the API
(none used in v1; callers must not invent them).

## 3. API reference

The API is the class **`QuantaDb`** in the global namespace; every operation is
a **static method** (there are no procedural functions). Configuration is
process-global (read from php.ini / `QUANTA_DB_*` env at first use).
`$name` arguments are node names (basenames), never paths. Unless stated
otherwise, methods throw `\QuantaDbException` on I/O errors, lock timeout,
or index corruption — and return `null`/`false` only for "not found".

### Reads

```php
QuantaDb::get(string $name, ?string $lang = null): ?array
```
Returns the decoded data document, or `null` if the node or the requested
language file does not exist. `$lang = null` reads `data.json`. **No language
fallback** — fallback policy stays in userland (`NodeFactory`), so the API
returns exactly one file's content.

```php
QuantaDb::getRaw(string $name, ?string $lang = null): ?string   // v1.1
```
Returns the **raw JSON document as a string** (the exact stored bytes), or
`null` if the node or language file does not exist. Semantically
`get()` equals `json_decode(getRaw(), true)`; `getRaw()` exists so a hot call
site can pair a zero-syscall read (from shared memory, in the extension) with
PHP's native `json_decode`, which is faster than the implementation building
the array itself for all but trivially small documents. Same not-found and
`CORRUPT_JSON` semantics as `get()`.

```php
QuantaDb::path(string $name): ?string
```
Absolute directory path of the node, or `null`. Replaces
`Environment::nodePath()` (the symlink cache + `find` mechanism).

```php
QuantaDb::exists(string $name): bool
QuantaDb::meta(string $name): ?array
```
`meta` returns at least:
`['path' => string, 'father' => ?string, 'mtime' => int, 'generation' => int,
  'langs' => string[] /* '' for neutral */, 'is_link_target_of' => int]`.

```php
QuantaDb::children(string $father, array $opts = []): array
```
Direct children (names). `$opts`:
- `type`: `'all'` (default) | `'dirs'` (real dirs only) | `'links'` (symlinked members only)
- `include_hidden`: bool, default `false` — whether to include names starting
  with `_` (Quanta's "inactive" convention) — mirrors `scanDirectory()`.

```php
QuantaDb::links(string $target): array
```
Names of container nodes holding a symlink to `$target` (replaces the
`find -samefile` categories lookup).

### Queries

```php
QuantaDb::find(array $criteria, array $opts = []): array
QuantaDb::count(array $criteria): int
```
`$criteria` (all conditions AND-ed; keys optional):
- `father` — string: direct children of this node.
- `lineage` — string: any depth under this node.
- `in` — string: members of a container (dirs + symlinked targets), e.g.
  `['in' => 'acme-bookings-paid']`.
- `where` — map of dot-path into the data document → scalar, equality only in
  v1: `['where' => ['booking_status' => 'paid', 'customer.country' => 'IT']]`.
- `name_prefix` — string.

`$opts`:
- `return`: `'names'` (default) | `'data'` (map name → data document) | `'meta'`.
- `order_by`: `'name'` (default) | `'mtime'` | `'json:<dot-path>'`; `order`: `'asc'|'desc'`.
- `limit`, `offset`: ints.
- `lang`: which document `where`/`return: data` read; default neutral.

v1 deliberately supports equality only. Ranges/prefix on `where` values are a
contract extension (bump minor version), not an implementation liberty.

### Writes

```php
QuantaDb::put(string $name, array $data, array $opts = []): bool
```
Replaces the node's data document wholesale (same semantics as `saveJSON()`).
`$opts`:
- `lang`: target language file; default neutral.
- `father`: **required when the node does not exist yet**, and passing it
  declares *create intent* — creates the directory under father's path
  (atomic `mkdir`) or throws `QuantaDbException` with code `EXISTS` if the
  name is already taken anywhere in the tree. This is the name-reservation
  primitive replacing `getCandidatePath()`'s check-then-act loop. To update
  an existing node, call `put` without `father` (moving nodes is out of
  scope in v1).

Durability sequence (normative): serialize → write `data.json.tmp.<pid>` →
`rename()` over `data.json` → bump generation in index → release lock.
Readers can never observe a partial document.

```php
QuantaDb::update(string $name, callable $fn, array $opts = []): ?array
```
Locked read-modify-write: acquires the node's exclusive lock, calls
`$fn(?array $current): ?array`. If `$fn` returns an array, it is persisted as
by `put`; returning `null` aborts without writing. Returns what `$fn`
returned. This is the ONLY correct way to do counter-style mutations.
Nesting `update` calls on *different* nodes is allowed but discouraged;
nesting on the same node deadlocks by contract (locks are not reentrant).

```php
QuantaDb::delete(string $name): bool
```
Moves the node directory to the trashbin (`tmp/trashbin/<timestamp>/`),
removes index rows and any container links pointing at it. Returns `false`
if the node does not exist.

```php
QuantaDb::link(string $target, string $container, array $opts = []): bool
QuantaDb::unlink(string $target, string $container, array $opts = []): bool
QuantaDb::relink(string $target, string $from_container, string $to_container): bool
```
Create/remove a symlink to `$target` inside `$container`'s directory.
`$opts['if_exists'] / ['if_not_exists']`: `'ignore'` (default) | `'error'` —
mirrors `NodeFactory::linkNodes` behavior. `relink` performs unlink+link
**atomically under the target's lock** — this is the primitive for
`BookingFactory::changeBookingStatus()`, so a concurrent reader never sees a
booking in zero or two status folders.

### Maintenance / introspection

```php
QuantaDb::reindex(?string $subtree = null): array   // ['nodes'=>int,'links'=>int,'seconds'=>float]
QuantaDb::stats(): array                            // impl-defined; MUST include 'implementation','contract','nodes'
QuantaDb::version(): string                         // e.g. 'polyfill/1.1' or 'ext/1.1'
```
`reindex` drops and rebuilds derived data from the filesystem (whole tree or
one subtree). Safe to run at any time, including concurrently with traffic.
Wired into the `doctor` module.

Beyond the required keys, the extension's `stats()` also returns the live
shared-memory counters (present when metrics are enabled). Reads/writes/deletes,
per-op **query** counts (`children_ops`, `find_ops`, `count_ops`, `links_ops`,
`link_ops`, `unlink_ops`), average **and peak** latency (`read_ns`/`read_ns_max`,
`write_ns`/`write_ns_max`), the read-path mix (`index_serves`/`file_reads`/
`fallback_reads`), lookup resolution (`shm_hits`/`authoritative_misses`/
`fs_heals`/`node_misses`/`neg_hits`), lock contention, daemon coherence
(`watch_*`, `data_epoch`, `uds_*`) and error counters. The same arena drives the
**`qdbstat`** monitor — a varnishstat-style dashboard (`qdbstat --once`, live, or
`--json`) grouping these into a health verdict + Daemon/Storage/Reads/Lookups/
Queries/Writes/Health sections; run it inside the pod
(`kubectl exec <pod> -- qdbstat --once`). Counters are per-pod and reset when the
extension is (re)deployed.

## 4. Cache tiers and read consistency (normative guarantees)

Implementations MAY serve reads from any tier:

| Tier | Storage | Who |
|---|---|---|
| 1 | Decoded document cache (per-process array cache, keyed by generation — parse-avoidance only) | optional |
| 2 | Index (polyfill: SQLite; extension: `qdbd`'s shared-memory segment holding name → path, father, mtime, generation, children, links, raw JSON docs) | required |
| 3 | The files | required (source of truth) |

In the extension the tier-2 segment is the primary store: while the daemon is
coherent every read is a shared-memory hash probe with no filesystem access. If
the daemon is down the extension degrades to a per-process filesystem walk
snapshot + direct file reads (tier 3) — the per-process caches and legacy
paths are the fallback, never the design point.

Guarantees every implementation MUST provide:

1. **Read-your-writes, cross-process.** Once `put`/`update`/`delete`/`relink`
   returns, every subsequent API read in ANY process reflects it. Mechanism:
   the write bumps the node's generation and the tier-2 store is updated under
   lock before the call returns; tier-1 caches are keyed by
   `(name, lang, generation)` and thus self-invalidate. In the extension the
   write notifies `qdbd` over the unix socket and the daemon acks only **after**
   publishing the new record to shared memory, so the guarantee holds across
   processes without polling. Wall-clock mtime is NOT sufficient (1-second
   granularity) — generation is the authoritative invalidator for
   API-originated writes.
2. **External-write detection.** Changes made *bypassing* the API (rsync,
   manual edits, legacy code not yet migrated, another replica writing a shared
   volume) MUST be observed:
   - `verify_reads = always`: every `get`/`find` stats the file and compares
     `mtime+size` against the index row; mismatch → re-read file, refresh row,
     bump generation. (Polyfill default; extension **fallback** mode.)
   - `verify_reads = watch`: a filesystem watcher patches the tier-2 store;
     reads skip the stat. Staleness bound: 500 ms. This is the extension's
     effective mode whenever `qdbd` is coherent — the daemon watches the
     docroot (directory events **and** `data*.json` content events) and
     republishes affected records, so a coherent segment reflects external
     writes within the watcher's latency.
   - `verify_reads = never`: trust the index (only sane for read-only CLI
     jobs). 
3. **Miss self-healing.** A tier-2 miss for a name MUST fall back to a
   filesystem search (equivalent of today's `find`) before answering
   "not found", and heal the store with the result. A negative result may be
   cached per-request only. In the extension this applies in fallback mode; a
   coherent daemon keeps the segment authoritative, so a miss there is a
   definitive absence (the daemon's inotify/reconcile already reflects any
   out-of-band create).
4. **Corruption fallback.** If derived data is unreadable/corrupt, the
   implementation MUST behave as if `reindex` were pending (serve from files,
   rebuild), never throw corrupted state at callers.

## 5. Locking (normative)

- One exclusive advisory lock per node name guards: the write sequence in
  `put`, the whole of `update`, `delete`, and `relink` (target's lock).
- Both implementations: `flock()` on `<lock_dir>/<name>.lock` — the kernel
  releases it if the holder dies, so a crashed worker never wedges a node.
- Lock acquisition times out after `quanta_db.lock_timeout_ms` →
  `QuantaDbException` code `LOCK_TIMEOUT`.
- Locks are NOT reentrant and NOT ordered by the API; callers holding a lock
  (inside `update`) must not synchronously wait on other nodes' locks in
  opposite orders. Keep `$fn` small and pure.

## 6. Configuration

Read from `php.ini` (`ini_get`), with environment-variable fallback for the
polyfill (`QUANTA_DB_*`), highest-precedence first: ini → env → default.

| Key | Default | Meaning |
|---|---|---|
| `quanta_db.root` | *(required)* | Absolute path of the data root (the site's `db/` docroot — see §8 multi-site note) |
| `quanta_db.index_path` | `<tmp>/quanta_db/<hash>/index.sqlite` (polyfill) | Polyfill index location. MUST NOT be on NFS. |
| `quanta_db.shm_dir` | `/dev/shm/quanta_db/<hash>` (extension) | Directory of the `qdbd` data segments (`data.<epoch>.shm`). tmpfs preferred; falls back to `<tmp>/quanta_db/<hash>` |
| `quanta_db.socket_path` | `<tmp>/quanta_db/<hash>/qdbd.sock` (extension) | `qdbd` unix socket for write notifications |
| `quanta_db.lock_dir` | `<tmp>/quanta_db/<hash>/locks` | Per-node `flock` files |
| `quanta_db.lock_timeout_ms` | `5000` | Per-node lock wait budget |
| `quanta_db.write_ack_timeout_ms` | `250` | Extension: budget for a `qdbd` write ack before poisoning coherence and returning (the write is already durable on disk) |
| `quanta_db.shm_size_mb` | `64` | Extension: initial per-epoch data-segment budget (sparse on tmpfs; grown by compaction) |
| `quanta_db.verify_reads` | `always` (polyfill) / effectively `watch` when `qdbd` coherent (extension) | §4.2 |
| `quanta_db.neg_cache_ms` | `30000` | Fallback-mode TTL for the per-process known-absent cache |

## 7. Errors

Single exception class, present under the same FQN in both implementations:

```php
class QuantaDbException extends \RuntimeException {
  // ->getCode() is one of:
  const IO = 1;            // read/write/rename/mkdir failure
  const LOCK_TIMEOUT = 2;
  const EXISTS = 3;        // put() with father for an existing name
  const BAD_ARGS = 4;      // malformed criteria, path-like $name, etc.
  const CORRUPT_JSON = 5;  // data.json exists but does not parse; message carries path
}
```
"Not found" is never an exception (`null`/`false`), matching how Quanta
treats missing nodes today.

## 8. Compatibility rules

- **Dispatch**: polyfill bootstrap does
  `if (!extension_loaded('quanta_db')) require 'polyfill.php';`.
  Call sites use the functions unconditionally.
- **Signatures are frozen** within a major contract version. New capabilities
  arrive as new `$opts`/`$criteria` keys (minor bump); unknown keys MUST
  throw `BAD_ARGS` (so silent divergence between implementations is
  impossible).
- **Return-shape parity**: for every function, polyfill and extension MUST be
  byte-equal after `var_export()` normalization on the conformance suite
  (array key order included — both use file/document order).
- **Multi-site note**: v1 binds one root per PHP process (`quanta_db.root`).
  Quanta's per-host `Environment` continues to exist; the integration shim
  routes through `quanta_db_*` only when `env->dir['db']` matches the
  configured root, else falls back to legacy paths. Multi-root is an explicit
  v2 topic.

## 9. Integration map (where legacy paths get replaced)

| Legacy choke point | Replacement |
|---|---|
| `Environment::nodePath()` (symlink cache + `exec find`) | `QuantaDb::path()` |
| `JSONDataContainer::saveJSON()` (`fopen 'w+'`) | `QuantaDb::put()` |
| `Node::loadJSON()` | `QuantaDb::get()` (via meta for `jsonpath`) |
| `Environment::scanDirectory()` in `ListObject` / `DirList` / `FastDirList` | `QuantaDb::children()` |
| `NodeFactory::linkNodes/unlinkNodes` | `QuantaDb::link()` / `QuantaDb::unlink()` |
| `BookingFactory::changeBookingStatus()` | `QuantaDb::relink()` |
| `BusinessBookingsTotalCount` recursive scans | `QuantaDb::count()` |
| `Environment::getCandidatePath()` retry loop | `QuantaDb::put(..., ['father'=>…])` + `EXISTS` |
| `Node::getCategories()` (`exec find -samefile`) | `QuantaDb::links()` |
| `Node::delete()` (`exec mv`) | `QuantaDb::delete()` |
| `doctor` module | `QuantaDb::reindex()` / `QuantaDb::stats()` |

Quanta is vendored; these touch points change inside `quanta/` and must be
re-applied when Quanta is re-vendored (see README "Updating Quanta") — keep
each shim a one-line delegation so the diff stays trivial.

## 10. Conformance suite

One PHPUnit suite, parameterized over the implementation, is the real
contract. Minimum scenarios:

1. put(new, father) → get/path/meta/children agree; second put(new) throws `EXISTS`.
2. put(existing) visible via get in a *different* PHP process (read-your-writes).
3. Torn-read impossibility: reader loop during 1000 rapid puts never sees invalid JSON or a partial document.
4. update(): two processes incrementing a counter 500× each → exactly 1000.
5. Two same-second writes both observed (generation, not mtime, invalidates).
6. External write (direct `file_put_contents` on `data.json`) observed per `verify_reads` policy.
7. relink(): concurrent readers of both containers always see the member in exactly one.
8. delete() → trashbin layout matches legacy `Node::delete()`; links to it are gone.
9. find/count criteria matrix incl. `where` dot-paths, `in`, ordering, limit/offset.
10. reindex() after wiping derived data restores answers 1–9.
11. Index-miss self-heal: node created by legacy code (plain mkdir+file) is found.
12. Lock timeout raises `LOCK_TIMEOUT`; crashed-holder recovery (kill -9 during update) leaves node writable.

## 11. Non-goals in v1 (explicit)

- Node move/rename between fathers; language fallback; multi-root;
  range/prefix `where` operators; transactions spanning multiple nodes
  (beyond `relink`); the lazy shm "view object" class (extension v2);
  replacing the files as source of truth (never).
