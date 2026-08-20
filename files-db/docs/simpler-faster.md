# files-db: simpler to use, and faster

A design review of the read path end to end — PHP caller → extension → shared
memory — with the concrete changes that would make files-db cheaper to call and
cheaper to run, ranked by payoff against risk.

Written to answer a narrower question ("can we store only the image, no JSON?"),
which turned out to be the *seventh* most valuable change on the list. The
image-only question is answered in full in §6; the six things worth doing first
are §4 and §5.

**Status:** implemented and measured. See §9 for what the production tree
actually said and what each step moved — several of the estimates below were
wrong, and the corrections are more interesting than the estimates.

---

## 1. Method, and what is actually measured

| Claim class | How it was established |
|---|---|
| Code structure, call chains, line references | Read directly from the sources below |
| Segment size ratios (§6) | `tests/bench/image-size.py` — a byte-faithful re-implementation of `src/image.rs` `Encoder` + `src/php_abi.rs::push_zend_string`, run over hili's 161 tracked `data*.json` |
| `loadJSON` ≈ 26% of render self-time, `storeNodePath` ≈ 13% of an admin list page | **Quanta's own profiling notes**, quoted from the code comments that record them (`Node.class.php:136`, `Cache.class.php:95`) — not re-measured here |
| Per-read microbenchmarks (0.24 µs / 27×) | `docs/how-it-works.md:903`, measured on `php:8.2-fpm` — quoted, not re-measured |
| Anything about **production** segment size or call volume | **Not measured.** No cluster was available. §8 lists exactly what to run |

Sources read: `files-db/src/{lib,image,model,store,shm,config,metrics,php_abi}.rs`,
`files-db/src/bin/qdbd.rs`, `files-db/docs/{api-contract,how-it-works,usage}.md`,
`files-db/docs/quanta_db.stub.php`, and the Quanta/hili call sites
`FilesDb.class.php`, `Node.class.php`, `Environment.class.php`,
`Cache.class.php`, `integrity.hook.inc`, `hili.hook.inc`,
`hili.doctor.hook.inc`.

> **The corpus caveat, stated once and applying to every number in §6.** The 161
> documents in hili's repo are structural nodes — components, guides, prompts,
> default data. Production content (jobs, bookings, users) is a different shape
> and almost certainly a different ratio. Every size number below is directional
> until `qdbstat` is run on prod. See §8.

---

## 2. The shape of the problem

files-db did the hard part correctly. `QuantaDb::getObject()` on a warm segment
is one hash probe and an image walk: no `stat`, no `read`, no `json_decode`,
and for large bodies no copy at all. Measured at 0.24 µs against 6.53 µs for the
legacy `file_get_contents` + `json_decode` — a 27× win on the operation itself.

The problem is that **almost none of that 27× survives the trip out to the
caller**, because the layers above it were built to protect against the cost
files-db removed, and they are still there, still paying.

Three separate taxes, compounding:

1. **The extension is asked the same question several times per node** (§4.1) —
   once for the path, once (or twice) for the document.
2. **A syscall-based cache still sits in front of the syscall-free lookup**
   (§4.2) — `Environment::nodePath()` does a `readlink()` on every call to avoid
   an `exec(find)` that files-db already eliminated.
3. **Every caller must implement the fallback protocol** (§3) — three-state
   returns, `coherent()` checks, path-identity comparisons, try/catch. 858 lines
   of PHP wrapper, and the hot path in `Node::loadJSON()` reimplements part of it
   again inline.

Nothing here is a defect in the extension. It is the cost of an incremental
migration that never got to delete the thing it replaced.

---

## 3. Simpler to use

### 3.1 The three-state contract leaks into every caller

`FilesDb::path()` returns `string | FALSE | NULL`:

- `string` — the path
- `FALSE` — definitively absent *and the index is authoritative*
- `NULL` — extension absent, errored, or not coherent; **caller must run its own
  lookup**

That third state is the expensive one, because it is not an error path — it is a
*routine* state that every call site must implement a complete second
implementation for. `Environment::nodePath()` handles it
(`Environment.class.php:796`), `Node::loadJSON()` handles it
(`Node.class.php:185`), `FilesDb::children()` handles it
(`FilesDb.class.php:486`), and each does so slightly differently.

`FilesDb::call()` (`FilesDb.class.php:822`) catches `\Throwable` and returns
`NULL` — collapsing "no such node", "daemon down", "stale `.so` without this
method" and "corrupt document" into one indistinguishable value, with the real
cause parked in `$last_error` where, in practice, nothing reads it.

### 3.2 `children()` needs a translation layer to be callable at all

`FilesDb::children()` (`FilesDb.class.php:486`) spends its first 20 lines
deciding whether the caller's request is even *expressible* in the extension's
vocabulary — mapping Quanta's `DIR_ALL`/`DIR_DIRS`/`DIR_FILES` × `symlinks`
`no`/`only`/`NULL` × `exclude_dirs` onto the contract's `type` +
`include_hidden`. When it is not expressible, it falls back to
`scanDirectory()` — and then has to `array_values()` the result because the two
implementations disagree about array shape in a way that changes what
`json_encode()` produces.

That is the signature of an API modelled on the *storage* rather than on the
*caller*. The extension should grow the two or three options that make the
common Quanta calls directly expressible, and `children()` should shrink to a
pass-through.

### 3.3 The single most common operation has no single call

"Load a node's document, with language fallback, verifying it is the node at
this path" is what `Node::loadJSON()` does, and it is *the* hot operation on the
site. Today (`Node.class.php:160-186`):

```php
$qdb_path = $this->env->quantaDbPathFor($this->name);   // probe 1 + path String
if (is_string($qdb_path)
    && ($qdb_path === $this->path || realpath($qdb_path) === realpath($this->path))) {
  $json = \QuantaDb::getObject($this->name, $language);  // probe 2
  if ($json === NULL && $suffix !== '') {
    $json = \QuantaDb::getObject($this->name, NULL);      // probe 3
  }
  ...
  if ($this->env->db()->coherent()) { ... }
}
```

Three probes, one absolute-path `String` built in Rust and thrown away, one
docroot rewrite, one PHP string compare, and — on the miss path — two
`realpath()` calls. The record found by probe 1 already contains everything the
other two probes go looking for.

**Proposal — one call, one probe:**

```php
QuantaDb::load(string $name, array $opts = []): ?array
// $opts: ['lang' => 'it', 'fallback' => true, 'at' => '/path/expected', 'as' => 'object'|'array']
// returns ['json' => stdClass|array, 'lang' => 'it', 'path' => '...', 'generation' => 42]
//   null  = no such node, or it is not the node at 'at'
```

`at` moves the path-identity check inside the probe that already holds the
record — no path `String` is built unless the caller asked for one. `fallback`
moves the language policy in beside the record that already lists every
language. This is the change that makes the caller *both* simpler and faster,
which is why it leads §4.

> The contract deliberately keeps language fallback in NodeFactory
> ("No language fallback (that policy stays in NodeFactory)",
> `quanta_db.stub.php:47`). That was the right call when `get()` was a primitive.
> `load()` is explicitly not a primitive — it is the composed operation, offered
> alongside the primitives, not replacing them.

### 3.4 What "simpler" should mean concretely

- One call for the common case; the primitives stay for everything else.
- **Coherent mode is the only mode a caller writes code for.** When
  `db()->coherent()` is true — the normal state — callers should not be
  implementing a second lookup. Fallback belongs *inside* `FilesDb`, not spread
  across its callers.
- Errors that mean different things should be distinguishable without reading
  `$last_error`.

---

## 4. Faster: the changes that don't need a format change

Ranked by (expected payoff ÷ risk). None of these touch the segment layout.

### 4.1 One probe per node load, not two or three

**Where:** `Node.class.php:160-186`, `Environment.class.php:876`,
`lib.rs:707` (`resolve_node`), `lib.rs:1656` (`get_object`).

The extension already knows this is wasteful — `with_shm_doc` carries the
comment:

> *"Going through `resolve_node` first would probe twice and build an absolute
> path `String` that a shared-memory read never looks at — the path is only
> needed on the fallback path, so it is only built there."* (`lib.rs:862`)

…and then the PHP caller reintroduces exactly that, from the outside. Every
`resolve_node()` call does `cfg.root.join(rec.rel_path()).to_string_lossy()
.to_string()` (`lib.rs:715`) — a `PathBuf` join plus a `String` allocation —
whether or not anyone wants the path.

**Fix:** `QuantaDb::load()` per §3.3. **Payoff:** removes 1-2 probes, one
`PathBuf`+`String` allocation, one docroot rewrite and one PHP string compare
per node load, on the function Quanta's own notes put at ~26% of render
self-time on admin list pages.

### 4.2 Stop putting a syscall cache in front of a syscall-free lookup

**Where:** `Environment.class.php:721` (`nodePath`), `Cache.class.php:133`
(`getStoredNodePath`), `Cache.class.php:74` (`storeNodePath`).

`Environment::nodePath()` resolves a name through four layers, in this order:

1. static `$node_paths[$folder]` array (per request)
2. a **shard symlink** under `tmp/cache/a/b/c/<name>` — `is_link()` + `readlink()`
3. `db()->path()` — the hash probe
4. `exec(find …)`

Layers 2 and 4 exist to avoid each other. Layer 3 made layer 4 obsolete — but
layer 2 was never removed, so it now costs more than the thing it protects.

**And the static cache does not avoid it.** Read the code carefully:

```php
if (isset($node_paths[$folder])) {
  $node_path_link = $node_paths[$folder];   // the LINK path, not the target
  ...
} else {
  $node_path_link = Cache::getStoredNodePath($this, $folder, FALSE);
}
$stored_target = @readlink($node_path_link);   // ← always, even on a cache hit
```

`$node_paths` stores the *symlink path*, not the resolved target, so **every**
`nodePath()` call issues a `readlink()` syscall — warm, cold, repeated within the
same request, all of them. PHP's stat cache does not cover `readlink()`. The
adjacent `is_dir()` is stat-cached after the first hit; the `readlink()` is not.

Quanta's own profiling note (`Cache.class.php:95`) records `storeNodePath` at
**~13% of an admin list page** before it was guarded. This is the same layer,
still on the read side.

**Fix, in two independent steps:**

- **(a) Cache the target, not the link.** Store the resolved path in
  `$node_paths[$folder]` and return it directly. Pure PHP, no files-db
  involvement, removes one syscall per name per request. On a page whose own
  profiling found 168 `findNodePath()` calls, that is ~168 syscalls per render
  for nothing.
- **(b) When `db()->coherent()`, ask files-db first and skip layers 2 and 4
  entirely.** The shard symlink cache only earns its keep in fallback mode,
  where `exec(find)` is the alternative. In coherent mode it is strictly
  negative: a `readlink()` (~1 µs with the syscall and dentry lookup) guarding a
  0.2 µs probe that needs no syscall at all.

This is the largest pure-win item on the list and it does not require touching
Rust.

### 4.3 `find()` resolves the same candidate up to three times

**Where:** `lib.rs:1452` (`find_impl`).

`find_impl` walks its candidate list up to three separate times, calling
`resolve_node()` — one probe plus one absolute-path `String` — in each:

| pass | line | condition |
|---|---|---|
| `where` filter | `lib.rs:1485` | `where` present |
| `order_by: mtime` / `json:` | `lib.rs:1498` / `1506` | non-name ordering |
| `return: data` / `meta` | `lib.rs:1535` | shaping the result |

A `find(['father' => x, 'where' => …], ['order_by' => 'json:date', 'return' =>
'data'])` over N candidates therefore costs **3N probes and 3N path `String`
allocations** where N record views would do.

**Fix:** resolve each candidate once into a small struct carried through
filtering, ordering and shaping. Contained, no API change, no format change.

**Related:** `limit`/`offset` are applied *after* the `where` filter loads every
candidate's document (`lib.rs:1525`). That ordering is semantically required —
you cannot page before you filter — but it means a `find` with `where` and
`limit: 20` over 5 000 candidates still decodes 5 000 documents. §4.4 makes
each of those decodes nearly free, which is the real fix.

### 4.4 Evaluate `where` by walking the image, not by decoding

**Where:** `lib.rs:1485`, via `load_doc` → `parse_cached` → `serde_json::from_slice`.

`where` is equality-only (v1). Today each candidate's whole document is parsed
into a `serde_json::Value` (or fetched from the parse cache, which then holds a
full `Rc<Value>` per node), and `json_value_at()` walks that.

The image can be walked directly: descend the map pairs, compare the target
key's `zend_string` bytes, bail at the first mismatch. **No parse, no
allocation, no parse-cache entry, and early exit on the first failing
predicate** — where today's path must materialize the entire document before it
can test one field.

This is a strict improvement over the current behaviour and it is the change
that makes `find(where:)` viable over large candidate sets. It also removes one
of the four consumers of the raw bytes (see §6).

### 4.5 `lineage` and `name_prefix` are full segment scans

**Where:** `lib.rs:1381` (`names_under`), `lib.rs:1400` (`names_by_prefix`).

Both call `seg.for_each_live()` — a walk of **every record in the segment** —
allocating a `String` per match and sorting afterwards. There is no index on
`rel_path` prefix, so `find(['lineage' => 'jobs'])` costs O(all nodes) even when
the answer is 12 names.

`father`/`in` do not have this problem: they go through `children_impl`
(`lib.rs:1202`), which reads the record's stored children list.

**Fix (cheap):** when `lineage` is combined with another criterion, evaluate the
cheap one first and filter, rather than intersecting two fully-materialized
sets (`find_candidates`, `lib.rs:1434`, currently builds every set in full and
then intersects). **Fix (real):** have qdbd maintain a lineage index in the
segment. That is a format change and belongs with §5.

### 4.6 `publish_full` triples the daemon's peak memory

**Where:** `qdbd.rs:229`.

```rust
let encoded: Vec<(String, Vec<u8>)> = self.model.nodes.iter()
    .map(|(n, m)| (n.clone(), m.encode()))
    .collect();                                     // ← whole tree, all at once
let live: u64 = encoded.iter().map(|(_, b)| b.len() as u64).sum();
...
let mut w = SegmentWriter::create(…)?;              // ← new segment mapped
for (name, bytes) in &encoded { w.upsert(name, bytes)?; }
...
self.writer = w;                                    // ← old segment dropped here
```

Every record is encoded into its own `Vec<u8>` and the whole set is held before
the writer is created. So during a compaction, qdbd's peak footprint is roughly:

```
model (raw + image, every document)
  + encoded copy (raw + image, every document, again)
  + the old segment mapping
  + the new segment mapping
```

`publish_full` runs on boot, on `reindex`, **and on every compaction**
(`maybe_compact`, `qdbd.rs:312`, called from six mutation paths). Given that this
system has already hit a `/dev/shm` sizing wall, a transient 3-4× spike in the
daemon during a routine compaction deserves attention.

**Fix:** size the segment with a cheap size-only pass (or an estimate plus
grow), then stream `encode()` → `upsert()` one record at a time so only one
record's bytes are live at once. This drops the "encoded copy" term entirely.

---

## 5. Faster: the one format change worth making first

### 5.1 A segment-wide string table

**Where:** `image.rs:86` (`Encoder.strings` — a `HashMap` **per document**).

Strings are deduplicated *within* a document. Across a tree of same-shaped
nodes, every single document re-emits `"title"`, `"author"`, `"language"`,
`"permissions"`, `"body"` as its own 32-byte `zend_string`.

Measured over hili's 161 documents (`tests/bench/image-size.py --dedup`):

```
per-document string tables (today): 2,176 entries, 100,648 B
segment-wide string table         :   970 entries,  57,872 B
saving                            :  42,776 B  (42.5% of string bytes)
distinct object keys in the tree  :   484
```

**42.5% of all string bytes, on 161 documents.** The ratio improves with tree
size, not with document size: 5 000 job nodes sharing ~30 keys dedup those keys
~5 000:1, where this corpus — 161 documents of mostly *different* shapes — is
close to the worst case for the technique.

For comparison, the entire image-only proposal (§6) saves 53,945 B on the same
corpus. **A segment-wide string table is in the same league here and will be
far ahead on production content — and unlike image-only it costs nothing in
capability.**

**Why it is tractable:** the image format is already pointer-free and
offset-addressed, and `Image::open()` already takes a slice. Strings would move
from image-relative to segment-relative offsets, which means carrying a second
base pointer through the reader and giving `Image` a companion arena. The
8-alignment invariant that makes zero-copy `zend_string`s work is unchanged. It
is a `layout_version` bump (currently 2, `shm.rs:32`), which readers already
reject on mismatch — so rollout is a bump plus a restart, with no migration.

**Where it gets harder:** interning is currently per-document and therefore
trivially garbage-collected — a republished record's strings die with the old
record. A segment-wide arena needs a policy for strings that are no longer
referenced. The honest answer is probably: don't refcount, let dead strings
accumulate, and let the existing compaction (`should_compact`) rebuild the arena
from scratch — which is exactly what `publish_full` already does for records.

---

## 6. The image-only question, in full

> *"Store only image data, no JSON — pure PHP objects. It reduces memory. What
> else? Other problems?"*

### 6.1 Is it possible?

Yes. `get()` and `getObject()` are already image-first (`lib.rs:1631`, `1656`)
and the image is a complete representation of the decoded document. Four paths
still read the raw bytes:

| consumer | line | servable from the image? |
|---|---|---|
| `find()` — `where`, `order_by: json:`, `return: data` | `lib.rs:1485`, `1506`, `1535` | **Yes, and faster** — see §4.4 |
| `update()` read-modify-write | `lib.rs:1826` | Yes — needs an image→`Value` reader, which already exists as `read_back` in `image.rs`'s test module (~30 lines to promote) |
| `load_doc` / `parse_cached` as the image's fallback | `lib.rs:950`, `1009` | Goes away with raw |
| **`getRaw()`** | `lib.rs:1682` | **No.** Its contract is "the exact bytes" |

### 6.2 The memory saving is real, but smaller than expected — the image is the *bigger* half

Measured with `tests/bench/image-size.py` over hili's 161 documents:

```
docs                 : 161
raw   total          : 53,945 B
image total          : 136,224 B   (2.53x raw)
segment doc payload  : 190,169 B   (raw + image, today)
image-only would save: 53,945 B    (28.4% of doc payload)
```

The image is **2.5× the JSON**, not half of it. And the driver is the *count of
distinct strings*, not the byte count — every string pays a 24-byte
`zend_string` header padded to 8:

| ratio | document | why |
|---:|---|---|
| 4.96× | `_country_phone_numbers/data.json` | 410 distinct strings → 13,120 B of headers for ~2 KB of text |
| 4.60× | `_country_codes/data.json` | 468 distinct strings → 14,976 B |
| 1.14× | `notification-send-link/data_it.json` | 16 strings, one of them a long body |

By size bucket:

| bucket | docs | raw | image | img/raw | image-only saves |
|---|---:|---:|---:|---:|---:|
| < 128 B | 79 | 3,837 | 15,932 | 4.15× | 19.4% |
| 128 B – 512 B | 54 | 14,282 | 42,228 | 2.96× | 25.3% |
| 512 B – 2 K | 25 | 24,195 | 35,392 | 1.46× | 40.6% |
| 2 K – 16 K | 3 | 11,631 | 42,672 | 3.67× | 21.4% |

**So the answer to "does it reduce memory" is: it depends entirely on document
shape.** Long-text documents → image ≈ 1.1× raw → dropping raw saves ~48%.
Many-short-fields documents — which is what job, booking and user nodes look
like — → image ≈ 3-5× raw → dropping raw saves ~20%, and leaves the expensive
half untouched. That last case is the one §5.1 attacks directly.

**The saving is doubled, though**, and this is the underrated half of the idea:
qdbd carries `DocModel.raw` in its own heap for the life of the model
(`model.rs:20`) purely to feed `encode()` on every republish. Drop raw from the
record and the model can drop it too — the daemon's RSS falls by the same amount
as the segment, and every `publish_node`/`publish_full` moves ~28% fewer bytes
(compounding with §4.6).

### 6.3 Problem 1 — `getRaw()` loses byte fidelity, and it has live callers

Re-serializing from the image is **not** byte-identical to the file:

- escaping normalizes — PHP's `\/` and `\uXXXX` vs serde's neither. This is
  precisely the hazard `putRaw()`'s own doc comment exists to warn about
  (`lib.rs:1774`).
- whitespace and indentation are gone
- float formatting changes; integers beyond `i64` already collapsed to `f64`
  (`image.rs:138`)
- duplicate keys collapsed to last-wins
- key order *is* preserved (serde with `preserve_order`) — that one is safe

And the `getRaw()` + `putRaw()` pair exists specifically to move a document
without rewriting it:

| call site | what it does |
|---|---|
| `hili.hook.inc:1002` | renames `data_<lang>.json` → `data.json` byte-for-byte |
| `integrity.hook.inc:154,229,259` | the same repair in Quanta core |
| `hili.doctor.hook.inc:838,866` | `str_replace($old_name, $new_name, $json_content)` **on the raw string**, then `putRaw()` |

That last one is the sharp edge. It does textual substitution on the raw
document during a node rename. Feed it re-serialized JSON and the doctor rewrites
the escaping of every document it touches — a spurious diff on every file, and
`\/`-escaped URLs come back unescaped.

**Mitigation, and it is a good one:** let `getRaw()` fall back to a disk read.
The branch already exists (`load_raw_by_name`, `lib.rs:1065`). `getRaw()` is
integrity/doctor/migration code, not the page-render path — it can afford an
`open`+`read`. This is the one design decision to make consciously, and the
answer is straightforward.

### 6.4 Problem 2 — un-imageable documents lose their in-memory copy entirely

Today a document that cannot be imaged still serves from the segment via
raw + parse-cache. With image-only, three classes fall all the way to disk on
**every** read:

- larger than `image_max_doc_kb` (256 KB default, `config.rs:50`)
- deeper than `MAX_DEPTH` = 512, or a container over `MAX_COUNT` = 16 M
  (`image.rs:30,34`)
- corrupt (already has no raw — no change)

Correctness is preserved; it is a performance cliff.

**Fix:** raise the cap to effectively unlimited. Its stated rationale *inverts*
under image-only — the comment says *"the image roughly doubles a document's
footprint"* (`config.rs:47-50`), but with no raw stored, imaging a large document
becomes the *cheaper* option, not the more expensive one. And files-db's own
benchmark already shows the image winning at that size: 205 KB document, 3.03 µs
imaged vs 3.22 µs parsed, **0.46 µs** with zero-copy (`how-it-works.md:903`).

### 6.5 Problem 3 — a PHP ABI mismatch stops being a slowdown and becomes a cliff

`check_php_abi()` + `hash_selftest()` (`lib.rs:2329`) runs at MINIT and sets
`IMAGE_ABI_OK`. It is a genuinely good check — it makes the *real* engine hash a
corpus and compares against the Rust port, catching the silent `char`-signedness
class of bug.

But note what the two states mean:

| `IMAGE_ABI_OK` | today | image-only |
|---|---|---|
| `true` | image path | image path |
| `false` | parse the raw bytes — correct, just slower | **every document read in the pod goes to disk** |

Same for `quanta_db.image=0`, which turns from a fast-path kill switch into a
switch that disables the database's entire reason for existing.

This matters more than it looks, because **`qdbd` does not link PHP** —
`image.rs` and `php_abi.rs` are PHP-free by design, so the daemon bakes an
*assumed* `zend_string` layout that the extension verifies later, in a different
process. Image-only makes the daemon and the PHP build version-coupled, and
files-db already has a documented failure mode where it degrades silently and
nobody notices until latency moves.

**Mitigation:** `IMAGE_ABI_OK == false` must become a loud, alarmed condition —
surfaced at the top of `qdbstat`, not buried as a counter — and ideally checked
at deploy time rather than discovered at runtime.

### 6.6 What does *not* break

Worth stating, because it bounds the whole risk:

- **Files on disk are untouched and remain the source of truth.** This is not
  "no JSON" — it is "no JSON *in the shared-memory cache*". Recovery from any
  image problem is a `reindex`.
- `put()` / `putRaw()` still take JSON and write JSON. Only the read cache
  changes.
- Corrupt detection is unaffected: qdbd parses every document anyway to set
  `corrupt` (`model.rs:load_docs`), which is also why building the image is
  nearly free — it reuses that `Value`.
- One contained fix needed: `with_shm_image` currently returns `None` on a
  corrupt language *on purpose*, so the raw path raises `CORRUPT_JSON` and the
  error text stays in one place (`lib.rs:817`). With no raw path, that error has
  to be raised from the image path directly.
- Rollout is a `layout_version` bump; readers reject foreign versions, so it is
  a restart, not a migration.

### 6.7 Verdict

Worth doing — **third**, not first. It is the smallest of the three memory
levers on shapes that look like production content (§6.2), it is the only one
that costs a capability (`getRaw()` fidelity, §6.3), and it removes the middle
rung of the degradation ladder (§6.5). Do §4.4 first (which removes three of the
four raw consumers for free and makes `find` faster), then §5.1 (which is the
bigger memory win and costs nothing), and then reassess whether the remaining
~20-28% is worth the blast radius.

---

## 7. Sequenced plan

Each step is independently shippable and independently measurable.

| # | Change | § | Touches | Format change? | Expected |
|---|---|---|---|---|---|
| 1 | Cache the resolved target, not the symlink path, in `Environment::nodePath()` | 4.2a | PHP only | No | −1 syscall per name per request |
| 2 | Skip the shard-symlink cache when `db()->coherent()` | 4.2b | PHP only | No | −1 to −3 syscalls per cold name; deletes a layer |
| 3 | `QuantaDb::load()` — one probe, path check + language fallback inside | 3.3, 4.1 | Rust + PHP | No | −1 to −2 probes and one `String` alloc per node load, on the ~26% function |
| 4 | `find_impl`: resolve each candidate once | 4.3 | Rust | No | −2N probes on filtered finds |
| 5 | Evaluate `where` by walking the image | 4.4 | Rust | No | `find(where:)` stops decoding whole documents; removes 3 of 4 raw consumers |
| 6 | Stream `publish_full` instead of collecting | 4.6 | Rust | No | Removes a full-tree copy from the daemon's compaction peak |
| 7 | Segment-wide string table | 5.1 | Rust | **Yes** (v3) | 42.5% of string bytes on this corpus; more on production shapes |
| 8 | Drop raw from the record | 6 | Rust | **Yes** (v4) | ~20-28% of doc payload on production-like shapes; same again off qdbd's RSS |

Steps 1-2 are pure PHP and need no coordination with the extension at all.
Steps 3-6 are behaviour-preserving. Steps 7-8 are the format changes and should
land in that order — 7 is strictly better value and, by shrinking the string
half, it also tells you exactly what 8 is still worth.

Steps 7 and 8 can share a single `layout_version` bump if they land together;
the table separates them because 7 should not wait for a decision on 8.

---

## 8. Measure these first

Nothing above needs a decision before these three numbers exist. All are cheap.

**1. The real image-to-raw ratio on production.** `qdbstat` already reports
`doc_bytes` and `img_bytes` separately (`qdbstat.rs:521-535` — the `images` row
prints image bytes as a percentage of document bytes). One command decides
whether §6 is a 45% win or a 20% one:

```sh
kubectl exec deploy/hili -- qdbstat            # the 'images' row
kubectl exec deploy/hili -- qdbstat --json     # doc_bytes / img_bytes / nodes
```

**2. The same modelling against the production tree**, which also gives the
per-document breakdown `qdbstat` cannot:

```sh
kubectl exec deploy/hili -- tar cf - /var/www/html/docroot --include='data*.json' | tar xf - -C /tmp/prod-docs
tests/bench/image-size.py /tmp/prod-docs --per-doc --dedup
```

The `--dedup` line is the one that sizes §5.1, which is the change most likely
to matter most.

**3. Probes and syscalls per render on `/it/adm-jobs-list/`.** `qdbstat` counters
before and after one request give `shm_hit`, `index_serve`, `img_serve`,
`img_absent`, `fallback_read`. The gap between `shm_hit` and the number of nodes
actually rendered is the size of the prize in §4.1 and §4.3. `strace -c -e
trace=readlink,stat,openat` on one fpm worker for one request sizes §4.2
directly.

The existing harness in `hili/docs/perf/qtag/README.md` already drives that
exact page against the local k3d cluster and records before/after, so steps 1-3
of §7 can be measured with `bench-qtag-all.sh` without new tooling.

---

## Appendix — reproducing the size numbers

`tests/bench/image-size.py` re-implements `image.rs`'s `Encoder` and
`php_abi::push_zend_string` byte for byte, so it models segment cost without a
daemon or a cluster:

```sh
tests/bench/image-size.py /path/to/docroot            # raw vs image totals
tests/bench/image-size.py /path/to/docroot --per-doc  # per-document ratios, worst first
tests/bench/image-size.py /path/to/docroot --dedup    # segment-wide string table saving
```

Every number in §6.2 and §5.1 came from that script run against
`/home/daylioti/adalot/hili`. If the encoder changes, the script must change
with it — it is a model, not a binding.

---

## 9. What actually happened

Everything in §7 was implemented and measured on the local k3d cluster against a
**restore of hili production** (105,755 nodes, 111,124 documents, 39.7 MB of
JSON on disk) — not the 161-document repo corpus §1 warned about. The harness is
`hili/docs/perf/filesdb/README.md`; the target page is `/it/adm-jobs-list/`
(4,990 rows, 6.4 MB of HTML), 30 timed renders per variant, no CPU quota.

Three builds: **`fdb0`** master, **`fdb1`** steps 1-7, **`fdb3`** steps 1-8.

### 9.1 Memory

| | `fdb0` | `fdb3` | change |
|---|---:|---:|---:|
| raw JSON resident in the segment | 39.7 MB | **0** | −100% |
| pre-decoded images | 118.4 MB | 37.1 MB | **−68.6%** |
| shared string table | — | 9.4 MB (87,590 entries) | new |
| **segment arena used** | **194.5 MB** | **82.8 MB** | **−57.4%** |
| segment file on `/dev/shm` | 386.0 MB | 165.8 MB | −57.0% |
| **`qdbd` RSS** | **796.3 MB** | **288.5 MB** | **−63.8%** |
| php-fpm worker RSS (6 workers, after load) | 2034.7 MB | 1498.2 MB | −26.4% |
| container `memory.current` | 954.3 MB | 546.2 MB | −42.8% |

The image is now **94% of the JSON it represents**, where it was **298%**.

### 9.2 Speed

| | `fdb0` | `fdb3` |
|---|---:|---:|
| render p50 | 1497.8 ms | 1401.0 ms (**−6.5%**) |
| render mean | 1498.6 ms | 1399.0 ms (−6.6%) |
| PHP CPU per render — **system** | 129.6 ms | 62.6 ms (**−51.7%**) |
| PHP CPU per render — user | 1347.7 ms | 1314.0 ms (−2.5%) |
| pod cgroup CPU per request | 1622.7 ms | 1527.9 ms (−5.8%) |
| throughput, 4 concurrent | 2.36 req/s | 2.52 req/s (+6.8%) |
| segment probes per render | 58,436 | 41,045 (−29.8%) |
| documents served per render | 23,282 | 23,285 (unchanged — same work) |
| PHP peak memory per render | 312.2 MB | 312.2 MB (unchanged) |
| masked body sha256 | `fd118b15b978…` | `fd118b15b978…` (**identical**) |

**System CPU halved.** That is the whole story of steps 1-2: the `readlink()`
per name per request is gone, and it was 67 ms of every render. User CPU barely
moved, which is the honest read on steps 3-5 — see §9.4.

### 9.3 Where the estimates were wrong

- **§5.1 was badly understated.** The 161-document corpus predicted a 42.5%
  saving on string bytes. Production gave **89.0%**: 2,112,806 per-document
  `zend_string`s (89.5 MB) collapsed to 87,590 shared entries (9.8 MB). The doc
  said "the ratio improves with tree size" — it improves much faster than that,
  because *values* dedup nearly as well as keys (statuses, dates, language
  codes, IDs repeat across a tree of same-shaped nodes).
- **§6 was understated too, but only because §5.1 landed first.** Dropping raw
  was projected at 20-28% of doc payload. Against the *already shrunken* payload
  it removed 39.7 MB of 122.6 MB — **32%** — and took the same again off the
  daemon's heap.
- **§4.1's premise was right, its size wrong.** The probes were real (58,436 per
  render against 23,282 documents, 2.5:1) and are now 41,045. But the extension
  was never the bottleneck: measured read time was **3.3 ms inside a 1,498 ms
  render, 0.2%**. Removing two thirds of the redundant probes could not have
  produced 26% of anything.

### 9.4 What one render actually asks for, before and after

`hili/scripts/bench-filesdb-counters.py` instruments the three PHP entry points
and takes exactly one authenticated render:

| per render | `fdb0` | `fdb3` |
|---|---:|---:|
| `nodePath()` calls | 5,761 | 5,762 |
| — `readlink()` syscalls issued | **5,760** | **0** |
| — reached `db()->path()` | 4 | 5,757 |
| — reached `exec(find)` | 0 | 0 |
| — shard symlinks written | 1 | 0 |
| `loadJSON()` calls | 22,917 | 22,920 |
| — path-identity probes | 22,917 | **0** (folded into the probe) |
| — `getObject()` calls | 45,746 | **0** |
| — `load()` calls | — | 22,920 |
| — of those, a doomed second language lookup | 22,834 | **0** |
| — `resolvesTo()` confirmations | — | 51 |
| `FilesDb::path()` calls (all callers) | 28,713 | 11,601 |
| segment probes | 57,376 | 40,300 |
| documents served | 22,861 | 22,864 |

Two lines carry the result. **`readlink` 5,760 → 0** is §4.2, and it is the
−51.7% system CPU. **68,663 extension crossings for document loads → 22,920** is
§3.3/§4.1: three calls per node became one, and the 22,834 guaranteed-miss
lookups stopped being issued at all. The documents served is unchanged to within
3, which is the check that none of it changed what the page reads.

The `resolvesTo()` row is worth noting: `load()` returns NULL for "no such
node", "not the node at `at`" and "no document" alike, so `Node::loadJSON` has
to confirm before treating that as an empty node. It costs a second probe — 51
times per render (0.2% of loads), against the 22,917 it removed.

Net PHP→extension crossings: **74,459 → 34,521, −54%**.

Where that shows up is worth being precise about. This page's render is
dominated by the 22,920 document materialisations themselves, not by the path
resolution the change removed — so the p50 win is 6.5%, not 50%. The saving is
in syscalls and FFI crossings, which is why it lands almost entirely in SYSTEM
CPU (−51.7%) rather than user CPU (−2.5%), and why the concurrent-throughput
number (+6.8%) moves more than the sequential one.

### 9.5 The finding the plan missed

Instrumenting the PHP side of one render (`hili/scripts/bench-filesdb-counters.py`)
found the actual shape of the load:

```
loadJSON_calls          22,917   for 5,755 DISTINCT nodes  -> each node ~4x
loadJSON_getobject      45,746   of which 22,834 (99.7%) were guaranteed misses
```

Two separate problems, only one of which §4.1 saw:

1. **Every language-suffixed lookup missed and repeated.** `getObject($name,
   'it')` found nothing for 99.7% of nodes and immediately asked again for the
   neutral document — so *half of all document lookups were known-doomed before
   they were issued*. `load()`'s `fallback` fixes this by construction: the
   record's language list is right there in the probe.
2. **Each node's document is loaded ~4 times per render.** Nothing in §7
   addresses this, and it is the largest remaining item: a per-request memo in
   `Node::loadJSON` would remove ~17,000 loads and ~17,000 probes per render.
   It was left alone deliberately — node reloads after a write are semantically
   load-bearing, so this needs its own invalidation design, not a cache slapped
   on the getter.

### 9.6 Decisions taken that the plan left open

- **A document is stored as its image OR its raw bytes, never both.** §6.4
  worried about un-imageable documents falling to disk on every read; instead
  they keep their raw JSON, so the segment can always answer. The saving is
  unaffected (production images every document), and the cliff never exists.
  `image_max_doc_kb` was raised 256 KB → 64 MB regardless, per §6.4.
- **§6.5's degradation ladder is real and is now documented, not silent.**
  `quanta_db.image=0` on a *worker* while the daemon still images means that
  worker has nothing in shared memory it will use — correct results, but a
  syscall per read. It is a deployment-wide setting now: give `qdbd`
  `QUANTA_DB_IMAGE=0` too and it ships raw JSON again. `qdbstat`'s STORAGE
  section reports `raw json` so the state is visible rather than inferred.
- **`getRaw()` reads the file** (§6.3's recommended mitigation). It also fixed a
  wart: in daemon mode it used to return `''` for a corrupt document — the one
  call whose purpose is inspecting a broken document could not show it to you.
- **String IDs are never renumbered.** A string no longer referenced keeps its
  slot in the daemon's interner until restart, but not its bytes in the segment:
  `resolve` places a string only when a record being written asks for it, so
  every compaction drops the unreferenced ones. Dead *string* bytes are not
  counted toward `should_compact`, so churn is bounded by genuinely new content
  plus the existing ArenaFull trigger — worth revisiting if a long-lived daemon
  ever shows arena growth without node growth.
- **`avg_read_ms` changed scope.** `load()` now times the whole segment read
  including materialising the document into zvals (~2.1 µs/document), where the
  old counter timed the byte fetch alone (~0.14 µs). It is the more honest
  number, but it is NOT comparable with the 0.24 µs figure in
  `how-it-works.md` — different operation, not a regression.

### 9.7 Test-suite changes worth knowing about

Three conformance tests encoded the old contract and were updated, each for a
reason that is itself a finding:

- `08_compaction.php` seeded 400 documents with an **identical** 800-byte pad.
  Tree-wide interning stores that once, so the arena stopped filling and the
  test passed every correctness assertion while never compacting. The pad is now
  unique per node and large enough to actually fill a 1 MB arena.
- `10_object_reads.php` asserted `getRaw()` returns `''` for a corrupt document
  in daemon mode. It returns the real bytes now.
- `13_image_off.php` asserted "no filesystem read" with images off. That is no
  longer true when only the reader has them off — see §9.6.

`05_external_reindex.php` remains intermittently flaky in daemon mode (the
inotify race its own `qdb_settle` comment documents); it failed once in ~10 runs
before and after this work.
