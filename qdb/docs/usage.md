# Using qdb from PHP

How to read and write Quanta's node database through the `quanta_db` extension.

For *why it is fast and how it is built*, see [how-it-works.md](how-it-works.md).
For the normative, versioned specification — frozen signatures, exact error
semantics, conformance scenarios — see [api-contract.md](api-contract.md). This
document is the practical guide: what to call, what comes back, what throws.

---

## 1. What this is

Quanta stores every node as **a directory containing `data*.json`**. That is the
database, and it stays the database. The extension does not replace it — it
removes the `exec(find)` / `exec(grep -r)` / unlocked-`fopen` work that Quanta
used to do to reach those files.

A companion daemon (`qdbd`) keeps the whole tree — names, paths, fathers,
children, symlink memberships, and the JSON documents themselves — in a
shared-memory segment that every PHP worker maps read-only. While the daemon is
healthy, a lookup is a hash probe: no `find`, no `open()`, no `json_decode`.

**If the daemon is down, nothing breaks.** The extension detects the missing
heartbeat and answers the same questions from a per-process filesystem walk —
the pre-daemon behaviour, just slower. Every method returns the same values in
both modes, with one documented exception (`getRaw()` on a document that does
not parse, [§5](#getraw)).

The entire API is the class **`QuantaDb`** in the global namespace. Every
operation is a **static method**; there are no procedural functions and no
objects to construct.

---

## 2. Is it available?

```php
if (!class_exists('QuantaDb')) {
    // Extension not loaded — use the legacy path.
}

QuantaDb::version();              // 'ext/1.3'
QuantaDb::coherent();             // true  = shared memory is authoritative
                                  // false = fallback mode (daemon down/stale)
QuantaDb::stats()['mode'];        // 'shm' | 'fallback'
```

`coherent()` is not a health check you need to poll — it matters in exactly one
situation, described in §8.3 below.

---

## 3. Setup

### Configuration keys

Every key is read from `php.ini` first, then the environment variable, then the
built-in default:

```
ini_get('quanta_db.X')  →  getenv('QUANTA_DB_X')  →  default
```

| ini key | Env var | Default | Meaning |
|---|---|---|---|
| `quanta_db.root` | `QUANTA_DB_ROOT` | **required** | Data root (the site's `db/` directory). Must exist; it is canonicalized and hashed to derive everything below. |
| `quanta_db.shm_dir` | `QUANTA_DB_SHM_DIR` | `/dev/shm/quanta_db/<hash>` | Data segments (`data.<epoch>.shm`). Falls back to `<tmp>/quanta_db/<hash>` when `/dev/shm` is absent. |
| `quanta_db.socket_path` | `QUANTA_DB_SOCKET_PATH` | `<base>/qdbd.sock` | `qdbd` write-notification socket. |
| `quanta_db.lock_dir` | `QUANTA_DB_LOCK_DIR` | `<base>/locks` | Per-node `flock` files. |
| `quanta_db.trashbin_dir` | `QUANTA_DB_TRASHBIN_DIR` | `<base>/trashbin` | Where `delete()` and `move(..., ['if_exists' => 'replace'])` put displaced content. **Set this** — the default is pod-local, whereas a Quanta site expects `static/tmp/<site>/trashbin`. |
| `quanta_db.lock_timeout_ms` | `QUANTA_DB_LOCK_TIMEOUT_MS` | `5000` | Per-node lock wait budget before `LOCK_TIMEOUT`. |
| `quanta_db.write_ack_timeout_ms` | `QUANTA_DB_WRITE_ACK_TIMEOUT_MS` | `250` | Budget for the daemon's write ack. Past it, coherence is poisoned and the call returns — the write is already durable on disk. |
| `quanta_db.shm_size_mb` | `QUANTA_DB_SHM_SIZE_MB` | `64` | Per-epoch segment budget. Consumed by `qdbd`, not the extension. Sparse on tmpfs, so it is a reservation rather than a cost. |
| `quanta_db.verify_reads` | `QUANTA_DB_VERIFY_READS` | `always` | Only `never` selects the other branch; every other value means `always`. See the note below. |
| `quanta_db.neg_cache_ms` | `QUANTA_DB_NEG_CACHE_MS` | `30000` | Fallback-mode TTL for the per-process known-absent cache. `0` disables. |
| `quanta_db.metrics` | `QUANTA_DB_METRICS` | on | The shared control plane + counters. `off`/`0`/`false`/`no` disables it — **and forces permanent fallback mode**, since coherence is published through it. |
| `quanta_db.metrics_path` | `QUANTA_DB_METRICS_PATH` | `<base>/metrics.shm` | Control/counter arena. |
| `quanta_db.image` | `QUANTA_DB_IMAGE` | on | Serve documents from the pre-decoded image, so a read does no JSON parsing at all. `off` falls back to parsing (correct, slower). |
| `quanta_db.image_max_doc_kb` | `QUANTA_DB_IMAGE_MAX_DOC_KB` | `256` | Documents larger than this are not imaged. |
| `quanta_db.zero_copy` | `QUANTA_DB_ZERO_COPY` | on | Point PHP string zvals straight at the mapping instead of copying. `off` is the kill switch. |

`<base>` is `<system temp dir>/quanta_db/<16-hex hash of the canonical root>`.
All three processes — extension, `qdbd`, `qdbstat` — derive it independently
from `quanta_db.root`, which is why they find each other with no registry.

> **`verify_reads` is currently inert.** It is parsed, registered and reported by
> `stats()`, but no code path reads it. Documented here because you will see it
> in `stats()` output; do not expect it to change behaviour.

### The one configuration trap

**Config is resolved once per PHP process, at the first call to any method** —
including `QuantaDb::coherent()`. An `ini_set()` *before* that first call takes
effect; after it, it is silently ignored. This matters mostly in tests and CLI
scripts:

```php
// tests/php/04_concurrency.php — this ordering is load-bearing
ini_set('quanta_db.lock_timeout_ms', '800');
fresh_env();   // in daemon mode this awaits coherence, which resolves config
```

### Container-level switches (not extension settings)

Several environment variables in the Docker image look like extension config but
are not. The extension has no `enabled` key and never reads any of these:

| Variable | Handled by | Effect |
|---|---|---|
| `QUANTA_DB_ENABLED=0` | `docker/docker-entrypoint.sh` | Deletes `conf.d/quanta-db.ini` so the `.so` is never loaded, and sets `QUANTA_DB_DAEMON=off`. |
| `QUANTA_DB_DAEMON=off` | `docker/qdbd-run.sh` | The supervisord wrapper exits immediately, so no daemon runs. The extension still loads and serves in fallback mode. |
| `QUANTA_DB_METRICS_LISTEN` | `docker/qdbstat-exporter.sh` | `host:port` for the Prometheus `/metrics` exporter. Unset, the wrapper exits 0 and no port is opened. |
| `QUANTA_DB_DASHBOARD_LISTEN` | `docker/qdbstat-dashboard.sh` | `host:port` for the web file browser. Unset, the wrapper exits 0 and no port is opened. |
| `QUANTA_DB_DASHBOARD_TOKEN` | `qdbstat --dashboard` | Optional shared secret for that dashboard; when set, every route but `/healthz` requires it. |
| `QUANTA_DB_DASHBOARD_BASE_PATH` | `qdbstat --dashboard` | URL prefix it is served under (e.g. `/qdb`), for an ingress that routes a sub-path to it. Empty = the root. |

Each runs as its own supervisord program on its own port, separate from nginx
and php-fpm, so turning either on changes nothing about how the site is served.
The two ports are not equivalent, though: `/metrics` carries counters, sizes and
epochs, while the dashboard carries **node paths and document bodies**. That is
why the exporter defaults to `0.0.0.0:9109` and the dashboard to
`127.0.0.1:9110` — see [the README](../README.md#web-dashboard).

---

## 4. The data model

| Concept | On disk |
|---|---|
| node | a directory under the root |
| node name | the directory's **basename** — this is the key, and it is **globally unique** across the whole tree, not scoped to a path |
| document | `data.json` (language-neutral, addressed as `null` / `''`) or `data_<lang>.json` |
| father | the node whose directory directly contains this one |
| children | subdirectories, plus symlinks pointing at other nodes |
| container / link | a symlink inside a container node's directory holding the **absolute** path of the target node's directory (categories, booking statuses) |
| generation | a monotonic per-node counter bumped by every write; the authoritative cache invalidator (mtime's 1-second granularity is not enough) |

Names are globally unique because the API resolves a name to a path — you never
build paths yourself. If two directories share a name, the first one found wins
and the other becomes unreachable, which is why creating a duplicate name raises
`EXISTS`.

**Directory classes.** `.git`, `_modules` and any dot-prefixed directory are
never treated as nodes. `assets` and `files` are *payload directories*: they are
walked and indexed, so `path('assets')` resolves and legacy lookups still find
things inside them, but their documents are never loaded and they are hidden
from `children()`. The configured shm/socket/lock/trashbin directories are
skipped if they happen to live inside the root.

---

## 5. Reading

### Which read do I want?

| Call | Returns | Parsing cost | Use when |
|---|---|---|---|
| `get()` | `array` — exactly `json_decode($raw, true)` | none on a cache/image hit | You want array access. |
| `getObject()` | `stdClass`, nested objects preserved | none on a cache/image hit | You want `$doc->a->b`. This is what `Node::loadJSON` uses. |
| `getRaw()` | `string` — the exact stored bytes | **re-parses on every call** if you decode it | You want the bytes: hashing, diffing, passing through. |

`getRaw()` is *not* the fast read. It costs no syscall, but pairing it with
`json_decode()` re-parses every single call, whereas `get()` / `getObject()`
reuse a per-process, generation-keyed cache and — when the record carries a
pre-decoded image — skip parsing entirely.

### `get()`

```php
QuantaDb::get(string $name, ?string $lang = null): ?array
```

```php
QuantaDb::get('home');            // ['title' => 'Home']
QuantaDb::get('acme', 'it');      // ['titolo' => 'Acme IT']
QuantaDb::get('acme', 'de');      // null — no language fallback
QuantaDb::get('missing-node');    // null
```

`$lang = null` reads `data.json`. **There is no language fallback**: the API
returns exactly one file's content, and fallback policy stays in `NodeFactory`.

### `getObject()`

```php
QuantaDb::getObject(string $name, ?string $lang = null): ?object
```

Returns exactly what `(object) json_decode($raw)` produces — JSON objects become
`stdClass` at *every* depth, JSON arrays become PHP lists.

> **`(object) QuantaDb::get()` is not the same thing.** The cast converts only
> the top level, leaving nested JSON objects as PHP arrays. Quanta reads them as
> objects (`$node->json->permissions->{$permission}`), so the difference is a
> real bug. Note that `json_encode()` cannot tell the two shapes apart — a
> string-keyed PHP array encodes as a JSON object — so if you compare shapes in
> a test, use `var_export()`, never a JSON round-trip.

**The result is mutable, and that is deliberate.** Every other reader on this
class returns values you must treat as immutable; `getObject()` is the exception.
It hands back a freshly allocated, unshared object you may write to, append to
and `unset()` at any depth, and two calls return two independent objects:

```php
$o = QuantaDb::getObject('p-mixed');
$o->title = 'changed';
$o->permissions->added = 'new';                 // nested object write
$o->files[] = ['name' => 'y.png', 'size' => 9]; // nested list append
unset($o->weight);

$b = QuantaDb::getObject('p-mixed');
$b->title;   // 'T' — untouched by the edits above
```

Nothing is written back to the store. Persist with `put()`.

### `getRaw()`

```php
QuantaDb::getRaw(string $name, ?string $lang = null): ?string
```

The stored bytes, verbatim. One asymmetry worth knowing: on a document that does
not parse, `get()`/`getObject()` throw `CORRUPT_JSON` but `getRaw()` does not —
it is the escape hatch for inspecting one. In daemon mode it returns `''` (the
daemon does not retain the bytes of a document it could not parse); in fallback
mode it returns the actual bytes off disk.

### `path()`, `exists()`, `meta()`

```php
QuantaDb::path(string $name): ?string     // absolute directory path, or null
QuantaDb::exists(string $name): bool
QuantaDb::meta(string $name): ?array
```

```php
QuantaDb::meta('acme');
// [
//   'path'              => '/var/www/.../home/businesses/acme',
//   'father'            => 'businesses',      // null at root level
//   'mtime'             => 1754985600,
//   'generation'        => 7,
//   'langs'             => ['', 'it'],        // '' is the neutral document
//   'is_link_target_of' => 2,                 // how many containers link here
// ]
```

`langs` is `[]` for a node with no documents at all — a legal state.

### `children()` and `links()`

```php
QuantaDb::children(string $father, array $opts = []): array
QuantaDb::links(string $target): array
```

`$opts` for `children()`:

- `type`: `'all'` (default) | `'dirs'` (real directories only) | `'links'`
  (symlinked members only)
- `include_hidden`: bool, default `false` — whether to include `_`-prefixed
  names (Quanta's `DIR_INACTIVE` convention)

```php
QuantaDb::children('items');            // ['item-a', 'item-b']
QuantaDb::children('items', ['include_hidden' => true]);
                                        // ['_hidden', 'item-a', 'item-b']
QuantaDb::children('cats', ['type' => 'links']);   // ['item-a']
QuantaDb::children('nonexistent');      // []  — not an error
QuantaDb::links('item-a');              // ['cats'] — containers linking here
```

Results are sorted by name. `links()` replaces the `find -samefile` categories
lookup.

---

## 6. Querying

```php
QuantaDb::find(array $criteria = [], array $opts = []): array
QuantaDb::count(array $criteria = []): int
```

All criteria are AND-ed. **Any key outside these lists throws `BAD_ARGS`** — by
design, so a typo is never a silently ignored filter.

**Criteria:**

| Key | Type | Meaning |
|---|---|---|
| `father` | string | direct children of this node |
| `lineage` | string | anything at any depth under this node (symlinks are not children) |
| `in` | string | members of a container — real subdirectories *and* symlinked targets |
| `where` | array | dot-path → **scalar**, equality only |
| `name_prefix` | string | node names starting with this |

**Options:**

| Key | Values |
|---|---|
| `return` | `'names'` (default) \| `'data'` (map name → document) \| `'meta'` |
| `order_by` | `'name'` (default) \| `'mtime'` \| `'json:<dot-path>'` |
| `order` | `'asc'` (default) \| `'desc'` |
| `limit`, `offset` | int |
| `lang` | which document `where` and `return: 'data'` read; default neutral |

```php
// Direct children, filtered on the document.
QuantaDb::find(['father' => 'b1-bookings', 'where' => ['status' => 'paid']]);
// ['bk-1', 'bk-2', 'bk-4']

// Dot-paths walk nested objects; multiple conditions AND.
QuantaDb::find(['father' => 'b1-bookings',
                'where'  => ['customer.country' => 'IT', 'status' => 'paid']]);
// ['bk-1', 'bk-4']

// Container membership — the BusinessBookingsTotalCount pattern.
QuantaDb::count(['in' => 'b1-paid']);   // 3
QuantaDb::count(['in' => 'b1-paid',
                 'where' => ['customer.country' => 'IT']]);   // 2

// Ordering, limit, offset.
QuantaDb::find(['father' => 'b1-bookings'],
               ['order_by' => 'json:amount', 'order' => 'desc', 'limit' => 2]);
// ['bk-2', 'bk-3']

// Shapes other than a name list.
$d = QuantaDb::find(['in' => 'b1-paid'], ['return' => 'data']);
$d['bk-1']['amount'];   // 10
$m = QuantaDb::find(['father' => 'b1-bookings'],
                    ['return' => 'meta', 'limit' => 1]);
$m['bk-1']['father'];   // 'b1-bookings'
```

Things to know:

- **`where` is equality-only in v1**, and values must be scalars — an array or
  object value raises `BAD_ARGS`. Ranges and prefix operators are a contract
  extension, not an implementation liberty.
- Dot-paths walk objects *and* numeric array indices (`items.0.sku`).
- **`count()` ignores `limit` and `offset`** — it counts the whole match set.
- `find()` does not hide `_`-prefixed names; only `children()` does.
- An empty result is `[]`, and a missing `father` is also `[]` — never an error.

---

## 7. Writing

Every write follows the same durable sequence: validate the name → take the
node's exclusive `flock` → mutate the filesystem atomically → notify `qdbd` and
wait for its ack → refresh local caches. The daemon acks only *after* the change
is visible in shared memory, which is what makes **read-your-writes hold across
separate PHP processes** with no polling.

The lock is held for the whole operation and released by the kernel if the
holder dies, so a crashed worker never wedges a node.

### `put()` and `putRaw()`

```php
QuantaDb::put(string $name, array $data, array $opts = []): bool
QuantaDb::putRaw(string $name, string $json, array $opts = []): bool
```

`$opts`: `lang`, `father`.

```php
// Update an existing node — no father.
QuantaDb::put('acme', ['title' => 'Acme 2']);

// Create — passing father declares CREATE INTENT.
QuantaDb::put('acme', ['title' => 'Acme'], ['father' => 'businesses']);

// Write a translation.
QuantaDb::put('acme', ['titolo' => 'Acme IT'], ['lang' => 'it']);
```

**`father` is the name-reservation primitive.** Passing it means "create this
node"; the directory is created with a single atomic `mkdir`, and if the name is
already taken *anywhere in the tree* the call raises `EXISTS`. This replaces
`getCandidatePath()`'s check-then-act loop. `put` never relocates a node — that
is `move`'s job. A new node **without** `father` raises `BAD_ARGS`.

`put()` replaces the document wholesale, exactly like `saveJSON()`.

**`putRaw()` exists to keep bytes stable.** PHP's `json_encode` escapes `/` and
non-ASCII; the extension's serializer escapes neither. A document that
round-trips through `put()` therefore comes back byte-different even though it
is value-identical — which matters when the files are under version control,
compared, or written by a mix of legacy and API code.

```php
$doc = ['u' => 'http://a/b', 't' => 'città', 'n' => 1];
$raw = json_encode($doc);            // contains \/ and à

QuantaDb::putRaw('rawdoc', $raw, ['father' => 'a']);
QuantaDb::getRaw('rawdoc') === $raw; // true — stored verbatim

QuantaDb::put('putdoc', $doc, ['father' => 'a']);
QuantaDb::getRaw('putdoc') === $raw; // false — re-serialized
```

`putRaw()` **validates the JSON before writing anything** and raises `BAD_ARGS`
if it does not parse. That is not pedantry: an unparsable document is latched as
corrupt by the daemon, and every later read of that node would throw
`CORRUPT_JSON`. Non-object roots (`[1,2,3]`) are legal and survive byte-for-byte.

### `update()` — the only correct read-modify-write

```php
QuantaDb::update(string $name, callable $fn, array $opts = []): ?array
```

Acquires the node's lock, loads the current document, calls
`$fn(?array $current): ?array`, and persists an array return as `put` would.
Returning `null` aborts without writing. `$opts`: `lang` only.

```php
$new = QuantaDb::update('counter', function (?array $cur) {
    $cur['n']++;
    return $cur;
});
// $new === ['n' => 1], and it is on disk

QuantaDb::update('counter', fn($cur) => null);   // returns null, writes nothing
```

Four processes each running this 150 times produce exactly 600 — that is the
point of the operation. Doing the same with `get()` + `put()` loses increments.

`update()` **cannot create a node**: if the node does not exist and your callback
returns an array, it raises `BAD_ARGS` telling you to use
`put(..., ['father' => …])`. (Returning `null` on a missing node just aborts.)

Locks are **not reentrant**: nesting `update()` on the same node deadlocks by
contract. Nesting on different nodes is allowed but discouraged — keep `$fn`
small, pure, and free of other database calls.

### `delete()` and `deleteDoc()`

```php
QuantaDb::delete(string $name): bool          // the node
QuantaDb::deleteDoc(string $name, ?string $lang = null): bool   // one document
```

`delete()` removes every inbound symlink, then moves the node's directory to
`<trashbin_dir>/<timestamp>/<name>`. Nothing is ever hard-deleted. Returns
`false` if the node does not exist.

`deleteDoc()` removes one language's file and leaves the node in place:

```php
QuantaDb::meta('trans')['langs'];      // ['', 'de', 'it']
QuantaDb::deleteDoc('trans', 'de');    // true
QuantaDb::meta('trans')['langs'];      // ['', 'it']
QuantaDb::deleteDoc('trans', 'de');    // false — already gone
```

A node with **no** documents is a legal state: it still resolves through
`path()`, `exists()` and `children()`, and `get()` on it returns `null`.

### `move()`

```php
QuantaDb::move(string $name, ?string $new_father = null, array $opts = []): bool
```

`$opts`: `name` (the new node name), `if_exists` (`'error'` default |
`'replace'`).

```php
QuantaDb::move('mover', 'b');                          // new father
QuantaDb::move('mover', null, ['name' => 'renamed']);  // rename in place
QuantaDb::move('renamed', 'a', ['name' => 'final']);   // both

// Trash whatever occupies the destination first, then move.
QuantaDb::move('mover', 'b', ['if_exists' => 'replace']);
```

The directory is renamed, so **the whole subtree travels with it** and every
descendant's path changes. Documents are not rewritten.

**Why this is not the same as renaming the directory yourself.** Links are
symlinks holding an *absolute* path, so a bare rename leaves every container
membership dangling. `move()` re-points each one (write a temporary symlink,
`rename()` it over the old one, so a reader never sees a missing member), and
when the name changes the link's own filename changes with it — a link is always
named after its target.

Atomicity is per step, not end to end: a concurrent reader always sees the node
at exactly one location and in exactly its containers, but a crash *between*
steps can leave dangling links, which `reindex()` repairs.

Returns `false` when `$name` does not resolve, and `true` as a no-op when source
and destination are the same place.

### `link()`, `unlink()`, `relink()`

```php
QuantaDb::link(string $target, string $container, array $opts = []): bool
QuantaDb::unlink(string $target, string $container, array $opts = []): bool
QuantaDb::relink(string $target, string $from, string $to): bool
```

`link()` takes `$opts['if_exists']`: `'ignore'` (default) | `'error'`.
`unlink()` takes `$opts['if_not_exists']`: `'ignore'` (default) | `'error'`.

```php
QuantaDb::link('item-a', 'cats');                    // true
QuantaDb::link('item-a', 'cats');                    // true — duplicate ignored
QuantaDb::unlink('item-a', 'cats');                  // true (a link was removed)
QuantaDb::unlink('item-a', 'cats');                  // false (nothing to remove)

QuantaDb::relink('item-b', 'st-unpaid', 'st-paid');  // atomic membership change
```

`relink()` is the primitive for status changes: it performs unlink+link as a
single `rename()` of the symlink under the target's lock, so a concurrent reader
never sees the node in zero or two status containers. If no source link exists it
simply links into the destination and returns `true`.

---

## 8. Errors

### 8.1 The five codes

```php
class QuantaDbException extends \RuntimeException {
    const IO           = 1;  // read/write/rename/mkdir failure
    const LOCK_TIMEOUT = 2;  // lock_timeout_ms elapsed
    const EXISTS       = 3;  // name already taken
    const BAD_ARGS     = 4;  // malformed name, unknown opts/criteria key, …
    const CORRUPT_JSON = 5;  // a *stored* document does not parse
}
```

**"Not found" is never an exception.** Missing nodes and missing languages
return `null`, `false` or `[]`.

Name validation applies to every method that takes a `$name`: empty, `.`, `..`,
or anything containing `/`, `\` or a NUL byte raises `BAD_ARGS`. Language codes
must match `[A-Za-z0-9_-]+`.

### 8.2 What throws what

| Situation | Code |
|---|---|
| `put`/`putRaw` create with a name taken anywhere | `EXISTS` |
| `put`/`putRaw` on a new node with no `father` | `BAD_ARGS` |
| `put`/`putRaw` with an unknown `father` | `BAD_ARGS` |
| `putRaw` with unparsable JSON | `BAD_ARGS` (not `CORRUPT_JSON`) |
| Unknown `$opts` or `$criteria` key, anywhere | `BAD_ARGS` |
| Path-like or empty `$name` | `BAD_ARGS` |
| `where` value that is not a scalar | `BAD_ARGS` |
| `update` callback returns a non-array, non-null | `BAD_ARGS` |
| `update` on a **missing node**, when the callback returned an array | `BAD_ARGS` |
| `link` with an unresolvable target or container | `BAD_ARGS` |
| `link` duplicate with `if_exists => 'error'` | `EXISTS` |
| **`unlink` with nothing to remove and `if_not_exists => 'error'`** | **`IO`** |
| `relink` with an unresolvable target or destination | `BAD_ARGS` |
| `move` to an unknown father, or into its own subtree | `BAD_ARGS` |
| `move` onto an occupied destination (`if_exists => 'error'`) | `EXISTS` |
| `move` renaming onto a name taken anywhere | `EXISTS` |
| `get`/`getObject` on a document that does not parse | `CORRUPT_JSON` |
| Lock not acquired within `lock_timeout_ms` | `LOCK_TIMEOUT` |

Two of those are genuinely surprising and are pinned by the conformance suite,
so do not "fix" them by catching the wrong class:

- **`unlink(..., ['if_not_exists' => 'error'])` throws `IO` (1), not
  `BAD_ARGS`.**
- **`update()` on a missing node throws only if your callback returned an
  array.** A callback that returns `null` aborts silently, missing node or not.

### 8.3 Authoritative absence — what `coherent()` is for

While the daemon is coherent, the segment is authoritative: a lookup miss means
the node **definitively does not exist**, and the answer comes back with no
filesystem access at all. In fallback mode a miss only means "the fast path does
not know", and a walk of the root follows.

That distinction is the whole reason a caller ever needs `coherent()` — it lets
you tell a definitive `null` from an uninformative one:

```php
$path = QuantaDb::path($name);
if ($path !== null) {
    return $path;                       // found
}
return QuantaDb::coherent()
    ? FALSE                             // definitively absent — skip legacy find
    : NULL;                             // don't know — fall through to legacy
```

This is exactly what `Environment::quantaDbPathFor()` does.

---

## 9. Recipes

**Atomic counter.** Never `get()` + `put()`:

```php
QuantaDb::update('counter', function (?array $cur) {
    $cur['n'] = ($cur['n'] ?? 0) + 1;
    return $cur;
});
```

**Create with a guaranteed-unique name.** Let `EXISTS` do the work instead of a
check-then-act loop:

```php
try {
    QuantaDb::put($name, $data, ['father' => $father]);
} catch (QuantaDbException $e) {
    if ($e->getCode() === QuantaDbException::EXISTS) {
        // name taken somewhere in the tree — pick another
    }
    throw $e;
}
```

**Atomic status change:**

```php
QuantaDb::relink($booking, 'bookings-unpaid', 'bookings-paid');
```

**Byte-stable rewrite** — keep a document identical on disk across writers:

```php
QuantaDb::putRaw($name, $exactBytes);
```

**Repair a node carrying both a neutral and a translated document** when only one
is correct. Write the survivor first, delete the other second, so an interruption
leaves a duplicate rather than nothing:

```php
QuantaDb::putRaw($name, $correctBytes, ['lang' => 'it']);
QuantaDb::deleteDoc($name);            // drop the neutral one
```

---

## 10. Inside Quanta

### One API, two implementations

Quanta reaches the node database through `$env->db()`, and never names the
extension. There are two classes behind that call, with the same methods:

| | |
|---|---|
| `Quanta\Common\FilesDb` | the filesystem — `exec find`, `scandir`, `file_get_contents`, `glob`, `mkdir`, `symlink`. Complete on its own. |
| `Quanta\Common\FilesDbExt` | extends it, and serves the same methods from this extension. Where it cannot, `return parent::…()` lands back on the filesystem. |

`Environment::db()` instantiates the subclass when `QuantaDb` is loaded and the
base when it is not. **Every method answers**: a call site never branches on
which implementation it got and never carries a second body for the case where
the extension declines. That is the whole design — see
`qdb/docs/two-implementations.md` for why the choice cannot simply be made
once per process.

`NULL` therefore means what it says. `path()` is `string|FALSE`; `find()`,
`children()`, `langs()` and `links()` return `array()` for "nothing matched";
`put()`, `link()`, `move()` and friends return a bool. Only `reindex()`,
`stats()` and `version()` return NULL on the base, because they describe a
derived index and there is not one.

Failures raise `Quanta\Common\FilesDbException` with this contract's codes,
from either implementation — the extension's `\QuantaDbException` is wrapped so
that a `catch` clause is not a fatal on a host with no `.so`.

### Where it is wired

| Call site | Method | Filesystem body it replaced |
|---|---|---|
| `Environment::nodePath()` | `path()` | the whole four-layer resolver, which now lives *in* `FilesDb::path()` |
| `Node::loadJSON()` | `load()` | `is_file` ×2 + `file_get_contents` + `json_decode` |
| `Node::hasChild()` / `hasChildren()` | `child()` / `children()` | `is_dir` / `scanDirectory` + `is_dir` loop |
| `Node::hasTranslation()` | `hasLang()` | `is_file` |
| `Node::getCategories()` | `links()` | `exec('find -L … -samefile')` |
| `Node::delete()` | `delete()` | `mkdir` + `exec mv` |
| `JSONDataContainer::saveJSON()` | `put()` | `mkdir` + unlocked `fopen('w+')` |
| `Environment::scanNodeDirectory()` | `children()` | `scanDirectory()` |
| `Environment::getCandidatePath()` | `exists()` | building a `Node` per attempt |
| `NodeFactory::linkNodes` / `unlinkNodes` | `link()` / `unlink()` | manual `symlink` / `unlink` |
| `NodeFactory::duplicate()` | `langs()` | `glob` + `preg_match` |
| `UserFactory::getUserFromField()` | `find()` with `where` | `exec('grep -r …')` — kept as a *widening*, see below |
| `FastDirList` | `path(['search' => FALSE])` | its own prefix-walk bypass |
| `Job::safeMove()` | `move()` | `exec('mv -T')` |
| `integrity` hook | `langs()` `raw()` `putRaw()` `deleteDoc()` | `glob` + `rename` + `unlink` |
| `sitemap` hook | `meta()` | `file_exists` + `filemtime` |
| `Doctor::checkBrokenLinks()` | `reindex()` | — |

Path resolution keeps all four tiers; they simply live in one class now, and
`FilesDbExt` puts the index in front of them:

```
per-request memo  →  tmp/cache shard symlink  →  QuantaDb::path()  →  exec find
```

When the index is coherent it is asked *first* and the shard symlink is skipped
entirely — a `readlink()` guarding a probe that needs no syscall is strictly
negative.

### Naming a node: `at`

The database is keyed by the globally unique node NAME, but a container can be
built from an explicit path (`NodeFactory::loadFromRealPath`). Every name-keyed
method takes `'at' => $path`, and a caller holding the directory passes it:

```php
$db->langs($node->getName(), array('at' => $node->path));
```

In `FilesDb` that *is* the answer — it reads the directory it was handed. In
`FilesDbExt` it is the identity check, made against the record the probe is
already holding. It replaced a `resolvesTo()` call sitting in front of nine call
sites for exactly this reason, and it costs the same one probe those did.

### What is deliberately still two things

Two call sites keep a second path, and neither is a fallback:

- **`UserFactory::getUserFromField()`** keeps its `grep`. `where` is equality
  only (§Queries) and the grep is case-INSENSITIVE, so an empty `find()` is a
  definitive "no exact match" and the grep is a genuine widening of the query.
- **`JSONDataContainer::saveJSON()`** passes `if_exists => 'ignore'` on create.
  Create intent reserves a node name tree-wide, so a name already in use raises
  `EXISTS` — where Quanta has always just made a second directory and warned
  about the duplicate later. `'ignore'` keeps that. Dropping the option turns
  the refusal into a user-visible error; that is a product decision, and it is
  named in one line rather than buried in a fallback.

### Writing a new call site

Call the method. There is no guard pattern any more:

```php
$path = $this->env->db()->path($name);       // string | FALSE
$langs = $this->env->db()->langs($name, array('at' => $dir));   // array
```

Catch `FilesDbException` where a failure needs a user-facing message. Do NOT
branch on `available()` or `coherent()` — they are there for `Doctor`, for the
parity suite's discriminators, and for reporting on a deployment. A call site
that branches on them is re-creating the problem this design removed.

---

## 11. Operations

### Checking health

```bash
qdbstat                 # live dashboard, refreshes every 1s
qdbstat --once          # one snapshot and exit
qdbstat --json          # one JSON snapshot, for scripting
qdbstat -n 2            # refresh every 2s

kubectl exec <pod> -- qdbstat --once
```

`qdbstat` maps the same control arena the extension writes to, and reports a
health verdict (Healthy / Degraded / Fallback / Unknown) plus daemon coherence,
segment usage, the read-path mix, latencies and lock contention. It is **per-pod**
— the arena and segment live in the pod's tmpfs, so there is no cluster-wide
view.

It finds the arena by deriving the same per-root directory the extension uses, so
it needs the data root: `--root <path>` or `$QUANTA_DB_ROOT` (set in the app
image), or explicit `--shm` / `--data-dir`.

From PHP, `QuantaDb::stats()` returns the same counters plus the resolved
configuration:

```php
$s = QuantaDb::stats();
$s['mode'];       // 'shm' | 'fallback'
$s['image'];      // 'on' | 'off' | 'abi-mismatch'
$s['nodes'];      // node count
$s['epoch'];      // active segment epoch (only when a segment is mapped)
```

`image => 'abi-mismatch'` means the extension was loaded into a PHP whose
`zend_string` layout it does not recognise, so the zero-copy read path disabled
itself. Reads still work; they just parse.

### Rebuilding the index

Everything in shared memory is *derived* from the files, so it is always
disposable:

```php
QuantaDb::reindex();            // whole tree
QuantaDb::reindex('subtree');   // one subtree
// ['nodes' => 1234, 'links' => 56, 'seconds' => 0.43]
```

Safe to run at any time, including under traffic. It has a 60-second socket
timeout; with no daemon running it counts from a filesystem walk instead.
Restarting `qdbd` has the same effect — it rebuilds from disk on boot.

You should not normally need it. The daemon watches the docroot with inotify and
runs a reconcile sweep every 60 seconds as a safety net, so out-of-band changes
(rsync, manual edits, another replica) are picked up automatically. `reindex()`
is the repair tool for the cases that leaves behind — notably dangling symlinks
after a crash mid-`move`.

### Legacy cache maintenance

When the extension is absent or in fallback mode, Quanta's own symlink path cache
still applies:

```bash
doctor <host> check         # find and re-resolve broken cache symlinks
doctor <host> clear-cache   # drop all cached node paths
```

---

## 12. Limits

- **One root per process.** `quanta_db.root` is bound at first use; multi-root is
  a v2 topic.
- **No language fallback.** `get($n, 'de')` returns `null` if `data_de.json` is
  absent, even when `data.json` exists. Fallback policy lives in `NodeFactory`.
- **`where` is equality-only**, on scalars.
- **Locks are not reentrant.** Nesting `update()` on one node deadlocks.
- **No API for payload files.** Uploads and images inside a node directory are
  walked for structure but never indexed; writing them stays ordinary filesystem
  work.
- **Names must be globally unique.** On a duplicate, the first directory found
  wins and the other is unreachable.
- **Treat every returned value as immutable except `getObject()`'s.**
- **No hard delete and no trashbin management.** `delete()` and
  `move(..., ['if_exists' => 'replace'])` only ever move content aside; listing,
  purging and restoring the trashbin are out of scope.
