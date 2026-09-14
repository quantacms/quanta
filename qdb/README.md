# quanta_db — qdb PHP extension (Rust)

Native PHP extension providing fast, concurrency-safe access to Quanta's
JSON-per-folder node database, plus a companion daemon (`qdbd`) and a monitor
(`qdbstat`).

**Documentation:**

| | |
|---|---|
| [**docs/usage.md**](docs/usage.md) | How to call it from PHP — every method, options, errors, recipes, how Quanta wires it in. **Start here.** |
| [**docs/how-it-works.md**](docs/how-it-works.md) | Internals — architecture, segment layout, the read/write paths, the daemon, coherence. |
| [docs/api-contract.md](docs/api-contract.md) | The normative, versioned specification (v1.3). |
| [docs/quanta_db.stub.php](docs/quanta_db.stub.php) | Signatures for IDEs and static analysis. |

In one paragraph: `qdbd` loads the whole node tree — paths, fathers, children,
links, and the JSON documents themselves — into a **shared-memory segment** that
every PHP worker maps read-only, so a lookup is a hash probe rather than an
`exec(find)`. Writes go to disk first (flock + fsync + atomic rename), then to
the daemon, which acks only after publishing, giving read-your-writes across
processes. Files stay the source of truth; everything in shared memory is
derived and rebuildable, so there is **no data migration**. If the daemon is
down, the extension falls back to a filesystem walk and the app is simply back
to the legacy profile.

## API

Class `QuantaDb`, all static:

```
get  getObject  getRaw  path  exists  meta  children  links  find  count
put  putRaw  update  delete  deleteDoc  move  link  unlink  relink
reindex  stats  coherent  version
```

plus `QuantaDbException` (`IO`, `LOCK_TIMEOUT`, `EXISTS`, `BAD_ARGS`,
`CORRUPT_JSON`). "Not found" is never an exception.

Since v1.3 the write side is complete: create (`put` with `father`), replace
(`put`/`putRaw`), read-modify-write (`update`), drop one translation
(`deleteDoc`), relocate or rename (`move`), delete (`delete`), and the link
operations. There is no node mutation left that has to be done by hand — see
"Adopting the write API" in the contract for what changes when a call site
switches over, and §11 for the payload-file exception.

## Configuration (php.ini, env fallback `QUANTA_DB_*`)

| Key | Default | |
|---|---|---|
| `quanta_db.root` | *(required)* | Data root (the site's `db/` directory) |
| `quanta_db.shm_dir` | `/dev/shm/quanta_db/<hash>` | Data segments (`data.<epoch>.shm`); tmpfs preferred, falls back to `<tmp>/quanta_db/<hash>` |
| `quanta_db.socket_path` | `<tmp>/quanta_db/<hash>/qdbd.sock` | `qdbd` write-notification socket |
| `quanta_db.lock_dir` | `<tmp>/quanta_db/<hash>/locks` | Per-node lock files |
| `quanta_db.trashbin_dir` | `<tmp>/quanta_db/<hash>/trashbin` | Destination for `QuantaDb::delete` and for whatever `move(..., ['if_exists'=>'replace'])` displaces. **Set this**: the default is pod-local derived storage, whereas a Quanta site expects deleted nodes under `static/tmp/<site>/trashbin` (`Environment->dir['trashbin']`) — the layout below it (`<ts>/<name>`) is already identical. The Docker image points it there |
| `quanta_db.lock_timeout_ms` | `5000` | Lock wait budget |
| `quanta_db.write_ack_timeout_ms` | `250` | Budget for a `qdbd` write ack before poisoning coherence and returning (the write is already on disk) |
| `quanta_db.shm_size_mb` | `64` | Initial per-epoch segment budget (sparse on tmpfs; grown by compaction) |
| `quanta_db.verify_reads` | `always` | Only `never` selects the other branch. Currently inert: parsed and reported by `stats()`, but read by no code path |
| `quanta_db.neg_cache_ms` | `30000` | Fallback-mode TTL for the per-process known-absent cache; `0` disables |
| `quanta_db.image` | `on` | Build the pre-decoded document image alongside the raw JSON, so a read needs no `json_decode` at all. `off` falls back to parsing (correct, slower) |
| `quanta_db.image_max_doc_kb` | `256` | Documents above this are not imaged (the image roughly doubles a document's segment footprint) |
| `quanta_db.zero_copy` | `on` | Point PHP string zvals straight at the mapping instead of copying. `off` is the kill switch |
| `quanta_db.metrics` | `on` | Shared-memory control plane + counters (heartbeat, active epoch, `qdbstat`); `off` forces permanent fallback |
| `quanta_db.metrics_path` | `<tmp>/quanta_db/<hash>/metrics.shm` | Control/counter arena (`MAP_SHARED`) |

Config is resolved once per PHP process at first use (`ini_set` before the first
call — including `QuantaDb::coherent()` — works; after it, it doesn't).

Note `QUANTA_DB_ENABLED` and `QUANTA_DB_DAEMON` are **container-level** switches
handled by the entrypoint and the supervisord wrapper, not extension settings —
see [docs/usage.md §3](docs/usage.md#container-level-switches-not-extension-settings).

## Running the daemon and the monitor

```bash
qdbd                 # started by supervisord (unless QUANTA_DB_DAEMON=off)
qdbd --once          # load + publish one snapshot, print status, exit
qdbd -v              # log every applied event

qdbstat              # live dashboard, auto-refreshing (default 1s)
qdbstat --once       # one snapshot and exit
qdbstat --json       # one JSON snapshot (for scripting)
qdbstat --prometheus # one Prometheus text exposition
qdbstat --listen     # serve /metrics on 0.0.0.0:9109 until killed
qdbstat --dashboard  # serve the web file browser on 127.0.0.1:9110 until killed
qdbstat --exit-code  # exit by verdict: 0 healthy, 1 degraded, 2 fallback, 3 none
qdbstat -n 2         # refresh every 2s
```

`qdbstat` locates the arena + segment by deriving the same per-root data dir the
extension uses, so it needs the data root: `--root <path>` or `$QUANTA_DB_ROOT`
(set in the app image), or explicit `--shm` / `--data-dir` overrides. The app
image ships it at `/usr/local/bin/qdbstat`:

```bash
kubectl exec <pod> -- qdbstat --once
```

**Per-pod:** the arena + segment live under the pod's tmp/tmpfs dirs, so
`qdbstat` reports the pod it runs in — there is no cluster-aggregated view.

### Prometheus metrics

`--listen` serves the same counters as a `/metrics` endpoint. supervisord starts
it when `QUANTA_DB_METRICS_LISTEN` is set (e.g. `0.0.0.0:9109`); unset, the
program settles in EXITED and no port is opened. It runs *inside* the web
container rather than as a sidecar because the counter arena is under
`/tmp/quanta_db`, on the container's own filesystem — a sidecar sees an empty
directory.

Families are namespaced `quanta_db_*`. The one worth building a panel on first
is compaction headroom, because it is the only form of the shm question that
predicts the failure instead of reporting it: a compaction maps the new segment
while the old one is still mapped, so what has to fit is **two** segments.

```promql
2 * quanta_db_segment_bytes / quanta_db_shm_total_bytes
```

`quanta_db_shm_*` comes from `statvfs` on the segment directory, not from the
segment header: `seg_size` is what a segment mapped, not what there is to back
it, and tmpfs lets an oversized mapping succeed and kills the writer later with
SIGBUS. Neither number is derivable from the other.

Counters are cumulative for the life of the pod, so `rate()` them rather than
reading a lifetime share: `quanta_db_fallback_read_ratio` is exported for
convenience but dilutes with uptime and cannot tell a live episode from an old
one — `rate(quanta_db_served_total{source="fallback"}[5m])` can.

### Web dashboard

`--dashboard` serves a read-only browser for **what the index actually holds**:
the node tree, each node's father, children and inlinks, and the document files
themselves. Where `qdbstat` and `/metrics` answer "is the index healthy",
this answers "what is in it" — which node is missing, which document failed to
parse, which child a father claims but the daemon never indexed.

```bash
qdbstat --dashboard                   # 127.0.0.1:9110
qdbstat --dashboard 0.0.0.0:9110      # or $QUANTA_DB_DASHBOARD_LISTEN
qdbstat --dashboard --base-path /qdb  # mounted under a URL prefix
```

supervisord starts it when `QUANTA_DB_DASHBOARD_LISTEN` is set
(`docker/qdbstat-dashboard.sh`); unset, the program settles in EXITED and no
port is opened, exactly as the exporter does. It is a separate port and a
separate process from nginx and php-fpm, so nothing about the site's own
serving changes when it is on.

| | |
|---|---|
| `QUANTA_DB_DASHBOARD_LISTEN` | `host:port` to serve on. Unset (or `off`/`0`/`false`) means the dashboard does not start |
| `QUANTA_DB_DASHBOARD_TOKEN` | Optional shared secret. When set, every route but `/healthz` requires `Authorization: Bearer <token>` or `?token=<token>` |
| `QUANTA_DB_DASHBOARD_BASE_PATH` | URL prefix it is served under, e.g. `/qdb`. Empty (default) means the root |

**Serving it under a sub-path.** An ingress that routes `/qdb` to a backend does
*not* strip that path — the request arrives spelled `/qdb/api/node` — so tell the
dashboard its own prefix rather than reaching for prefix-stripping middleware:

```yaml
- name: QUANTA_DB_DASHBOARD_BASE_PATH
  value: /qdb
```

With it set, every route moves under the prefix, `/qdb` redirects to `/qdb/`
(the page's assets and API calls are relative, and a browser resolves those
against the directory of the current URL — without the trailing slash they would
resolve outside the prefix), and nothing outside it is served. That keeps the
ingress plain: one path, one backend, no per-controller annotations.

Three views: **Files** (the node tree and every document), **Size** (what each
subtree weighs, as a treemap you can drill into) and **Stats** (the counters
`qdbstat` prints, in HTML).

The Size view answers "what is heavy, and where" with two metrics that routinely
disagree. *Documents on disk* sums each node's `data*.json`; *segment footprint*
sums what each node costs in shared memory — record header, name, path, father,
child and inlink lists, raw bytes and pre-decoded image. A tree of many tiny
translation documents is light on disk and heavy in the segment, because every
node pays a record; one un-imaged 2 MB document is the reverse. The second
metric is the one to open when sizing `/dev/shm`: nothing else in the tooling
attributes segment bytes to a *subtree*.

**Everything it shows comes from the shared-memory segment, never from disk.**
That is what makes it worth opening during an incident: it shows what a PHP
worker would be served, so a node that is on disk but not in the answer is the
finding, not a rendering bug. A document the daemon imaged is walked back into
JSON out of the segment (`image::to_value`); one too large to image says so
rather than being fetched from the file.

It reads the segment even with `quanta_db.metrics=off` — with no arena to name
the active epoch it takes the highest one in the segment directory — so it still
works on a box where no PHP worker has run.

Exposure is the one thing to decide deliberately. `/metrics` carries counters,
sizes and epochs; this carries **node paths and document bodies**, i.e. the
site's content. Hence the loopback default, which `kubectl port-forward` reaches
and the cluster does not:

```bash
kubectl port-forward <pod> 9110:9110   # with QUANTA_DB_DASHBOARD_LISTEN=127.0.0.1:9110
```

Set `QUANTA_DB_DASHBOARD_TOKEN` for anything wider — a bind address is not
access control. The dashboard is read-only by construction (a `PROT_READ`
mapping, no mutating route, and a node name from the URL is only ever a hash
key, never a path), so the risk it carries is disclosure, not damage.

## Build & test (Docker)

```bash
# Build the extension AND run the conformance suite (fails the build on red):
docker build -t quanta-db quanta/qdb/

# Extract the compiled quanta_db.so:
docker build --target artifact -o quanta/qdb/dist quanta/qdb/
```

Built against `php:8.5-fpm` so the `.so` matches the production image ABI.
Load it with `extension=/path/to/quanta_db.so`.

Both this Dockerfile and the root one take a `PHP_VERSION` build arg (default
`8.5`) and must be kept in step — PHP refuses to load a module built against a
different minor. Note that **8.5 is the ceiling of `ext-php-rs 0.15`**: its build
script rejects any Zend API newer than `20250925`, so moving to PHP 8.6 means
upgrading the crate first, not just bumping the arg.

The conformance suite runs in **two modes** (see `tests/run-tests.sh`):
`fallback` (no daemon) and `daemon` (a `qdbd` is spawned per test root and the
suite waits for coherence). Both must stay green. Rust unit tests
(`cargo test`) cover the segment layout, reader/writer, tombstones, compaction,
and a reader-under-churn torn-read stress test.

### Quanta call-site parity tests

The conformance suite proves the *extension* obeys the contract.
`tests/run-quanta-tests.sh` proves *Quanta* obeys itself: every call site wired
to the node database keeps its original code as a fallback, and the two
branches must be indistinguishable from the outside. It boots a real
`Environment` against a throwaway site and runs the same assertions in **three**
modes — `noext` (the `.so` is not loaded at all), `fallback`, and `daemon`:

```bash
docker run --rm --entrypoint sh -v "$PWD/qdb/tests:/tests:ro" \
    <quanta-image> -c 'sh /tests/run-quanta-tests.sh'
# QDB_MODES="noext fallback"  to restrict
```

It needs a real checkout (`src/` + `vendor/`), so it runs inside the image
rather than from a bare source tree. `noext` mode is produced the same way
`QUANTA_DB_ENABLED=0` does it — a copy of `conf.d` without `quanta-db.ini`, so
every *other* PHP extension stays loaded.

Assertions that only hold with the extension use `ok_ext()`/`eq_ext()`, and
ones that need an authoritative index use `ok_daemon()`; both report as
**skipped** rather than passing, so the output shows what each mode actually
proved. The sharpest discriminator is `written_by_extension()`: `json_encode`
escapes `/` and non-ASCII and the extension's serializer does not, so the bytes
on disk say which writer produced a document.

Parity alone cannot catch a shim that quietly stopped using the extension —
both branches would still agree. `tests/quanta/06_wired.php` closes that from
the other side with the live counters from `QuantaDb::stats()`: it asserts that
`DirList` moves `children_ops`, `Job::safeMove` moves `moves`, `saveJSON` moves
`writes`, the integrity repair moves `raw_writes` + `doc_deletes`, and that a
node load leaves `file_reads` where it was. Un-wire any call site and that file
goes red while the parity files stay green.

Both suites gate the image push in `.github/workflows/docker-image.yml`.

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

Always check `stats()['mode']` is `shm` before trusting a benchmark — a run
without a daemon measures fallback mode and tells you nothing about the fast
path. See [docs/how-it-works.md §14](docs/how-it-works.md#14-performance) for the
measured numbers and the cautionary tale behind that warning.

### Demo page

`demo/index.html` is a self-contained animated before/after (lookup race,
torn-write vs atomic-rename, measured benchmark bars) plus a **live race** that
times `exec(find)` vs `QuantaDb::path()` on a random real node of the mounted
tree (`demo/live.php`). The main vhost intentionally blocks `.php` under the
site dir, so serve it with PHP's built-in server in the container:

```bash
docker compose run --rm -p 8090:8090 --entrypoint php web \
  -S 0.0.0.0:8090 -t /var/www/quanta/sites/localhost/quanta/qdb/demo
# open http://localhost:8090
```

### Local dev loop

```bash
docker build --target builder-base -t quanta-db-builder quanta/qdb/
docker run -d --name qdb-build \
  -v "$PWD/quanta/qdb:/ext" -v qdb-cargo:/opt/cargo/registry \
  -v qdb-target:/target -e CARGO_TARGET_DIR=/target -w /ext \
  quanta-db-builder sleep infinity
docker exec qdb-build cargo build --release
docker exec -e QDB_EXT=/target/release/libquanta_db.so \
  -e QDBD_BIN=/target/release/qdbd qdb-build sh tests/run-tests.sh
```

## Semantics notes (beyond the contract)

- **Directory classes.** `.git`, `_modules` and `.`-prefixed directories are
  never treated as nodes. `assets` and `files` are *payload directories*: they
  are walked and indexed (so they resolve by name — legacy `find` locates
  `assets/img`), but their documents are never loaded and they are hidden from
  `children()`. The configured shm/socket/lock/trashbin dirs are skipped if
  placed inside the root. Regression guard: `tests/php/09_payload_dirs.php`.
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
- `getRaw()` returns the raw JSON string, served from shared memory with no
  syscall. Note it re-parses on every call, so it is *not* the fast read:
  `get()`/`getObject()` reuse a generation-keyed parse cache and, when the
  record carries a pre-decoded image, skip parsing entirely.
- Duplicate node names: first directory found wins (legacy behavior is a
  warning); keep names globally unique as Quanta requires.

### Document reads: the pre-decoded image and zero-copy

`qdbd` already parses every `data*.json` (to detect corruption), so it also
stores a **pre-decoded image** of it in the record: a flat, tagged, pointer-free
encoding whose strings are ready-made `zend_string`s — correct PHP header,
8-aligned, with the precomputed non-zero DJBX33A hash. A read walks that image
straight into zvals: no tokenizing, no number parsing, no UTF-8 revalidation,
and (with `quanta_db.zero_copy=on`) no string allocation or copying at all.

Measured on `php:8.2-fpm` against a live daemon (`tests/bench/probe.php`,
20 000 iterations), per `Node::loadJSON`-equivalent read:

| document | legacy `fgc`+`json_decode` | image off | image on | image + zero-copy |
|---|---|---|---|---|
| 48 B | 6.53 µs | 0.36 µs | 0.25 µs | **0.24 µs** (27×) |
| 437 B | 7.19 µs | 0.64 µs | 0.48 µs | **0.44 µs** (16×) |
| 205 KB | 129.6 µs | 3.22 µs | 3.03 µs | **0.46 µs** (279×) |

Three safety properties guard it, because this hands the Zend engine pointers
into a read-only shared mapping: a **MINIT ABI check + live hash self-test**
(mismatch disables the image path and `stats()['image']` reads `abi-mismatch`;
MINIT never fails), **segment pinning** released in `post_deactivate` and capped
at 4 epochs per request, and the **`zero_copy` / `image` kill switches** (the
conformance suite runs all four combinations and results are byte-identical).
Full explanation in
[docs/how-it-works.md §6](docs/how-it-works.md#6-the-pre-decoded-image).

## Non-goals in v1

Language fallback (stays in `NodeFactory`), multi-root per process, range/prefix
`where` operators, an API for payload files, trashbin management, and a
cluster-aggregated `qdbstat` view (the segment + arena are per-pod).

*(Node move/rename was a v1 non-goal; `move()` delivered it in v1.3.)*
