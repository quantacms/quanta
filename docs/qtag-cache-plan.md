# Qtag rendering & cache tags — findings, plan, and measured outcome

Status: **steps 0–2 implemented and measured. Step 3 (a persistent qtag HTML
cache) is answered: no — see §3 and §7.**

Originally measured on hili-dev (`cmtest.hilitravel.com`, `/it/adm-jobs-list/`,
265 job rows, 360 KB HTML), Aug 2026. The outcome numbers in §6 come from a
before/after campaign on the local k3s cluster against the same page carrying
the full local dataset — ~4990 rows, 5.63 MB of HTML, ~110k qtag occurrences —
which is the same shape, an order of magnitude larger.

---

## 1. How qtag rendering works today

A qtag is template markup, `[TYPE|attr1=x|attr2=y:target]`. Rendering is string
substitution over the whole page:

```
transformCodeTags($env, $html)                    QtagFactory.class.php:94
  └─ while (TRUE):
       checkCodeTags()                            QtagFactory.class.php:31
         preg_match_all('/\[[A-Z][^\[\]{}]+\]/s', $html, $matches)
         foreach ($matches[0] as $tag_full):      // EVERY occurrence, duplicates included
             parseQTag()      → new Qtag object
             $qtag->preload()
             $replacing[$tag_full] = $qtag->getHtml();
       foreach ($replacing): $html = str_replace($tag_full, $out, $html)
       // repeat: rendered qtags can emit new qtags
```

`Qtag::preload()` guards the render with a per-request cache:

```php
$cached = Cache::get($env, 'qtag', $this->cacheTag());
if ($cached) { $this->html = $cached->html; }   // reuse
else         { $this->load(); }                  // render, then Cache::set(...)
```

`cacheTag()` is the qtag's **identity**, built from its complete input:

```php
json_encode($this->tag) .'_'. json_encode($this->attributes) .'_'. json_encode($this->target)
  → hash('crc32', …)
```

`Cache::get`/`set` is a plain PHP array on `$env->data['cached']['qtag']` —
**request-scoped**, discarded at the end of the request.

**The idea.** A page repeats the same markup constantly (the same label in all
265 rows, the same icon, the same date formatter). Since tag + attributes +
target are a qtag's entire input, two qtags sharing all three must produce the
same HTML — so render once per request and reuse.

That premise is already load-bearing elsewhere: `str_replace($tag_full, …)`
replaces **every** occurrence of the literal string page-wide with a single
result. "Same markup ⇒ same output" is baked into substitution, cache or not.
Context therefore has to be encoded in the attributes — which is why list rows
work: each row's markup carries its own `node=<name>`.

---

## 2. What was wrong

### 2.1 Deduplication happened at the wrong end of the pipeline — FIXED (step 2)

`preg_match_all` returns every occurrence. A qtag appearing 200 times built 200
objects, parsed attributes 200 times and computed 200 cache keys — then wrote
all 200 results into `$replacing[$tag_full]`, *a single array slot*. 199 were
overwrites of an identical value, and one `str_replace` fixed all 200
occurrences anyway.

The cache existed to make repeats 2…200 cheap. They should never be started.

Measured, one render (dev page):

| | |
|---|---|
| `cacheTag()` calls | 8450 |
| distinct keys | 2470 |
| `preload()` cache hits | 3582 |
| `preload()` misses | 2455 |

3582 qtags were constructed and hashed purely to look up an answer already known.

### 2.2 The key cost more than what it guarded — HALVED (step 0)

`cacheTag()` json_encoded the full attribute array and target on every call:
**5.76 MB of serialization per render** on the dev page, **103.7 MB** on the
local one. For a static label the key costs more than the render. The cache only
pays for genuinely expensive qtags.

### 2.3 `cacheTag()`'s own memo caches the wrong half

`static $hashed` is keyed on `$combinedString` — the very string the expensive
serialization produces. It saves the `crc32` and never the `json_encode`. Left
as it is: step 0 removes the duplicate call instead, which is the same saving
without a second memo.

### 2.4 The disk-cache branch had never executed — DELETED (step 1)

`Qtag.class.php` `preload()`:

```php
if (isset($qtag->attributes['cache'])) {   // $qtag is never assigned here — it is $this
```

`isset()` on an undefined variable returns false silently, so the whole
disk-cache path was dead. An instrumented counter on that branch never
incremented. `Text.qtag.php::build()` set `$this->attributes['cache'] = 'disk'`
expecting it to work — that assignment is gone too, and with it the `'cache'`
entry that every `[TEXT:…]` key had been serializing for nothing.

### 2.5 Some cache entries were write-only — FIXED (step 0)

Qtags that mutate their own attributes or target inside `render()` (Thumbnail,
Form, CategoryToggle) got stored by `Cache::set` under a **post**-mutation key,
while `preload()` looked up the **pre**-mutation one. 39 of 2413 on the dev
page; on the local page it shows up as 27 distinct keys that disappear in step 0
(47740 → 47713) — written, never readable.

---

## 3. The plan, and what it turned into

### Step 0 — pass the computed key from `preload()` into `load()` — DONE

Computes `cacheTag()` once in `preload()` and hands it to `load($cache_tag)`
instead of having `load()` rebuild it for `Cache::set`. Also fixes 2.5 as a side
effect, by storing under the key `preload()` actually looked up with.

`__toString()` still calls `load()` with no argument, which computes the key
itself — the signature is backward compatible, and no Qtag subclass overrides
`load()`.

### Step 1 — delete the dead disk-cache branch (2.4) — DONE

It had never run. Leaving it invited someone to "fix" it by changing `$qtag` to
`$this`, which would ship an **uninvalidated cross-user HTML cache** — see §5.
The `Text::build()` override that fed it is gone with it.

### Step 2 — deduplicate matches in `checkCodeTags` (2.1) — DONE

A `$seen` set keyed on the full markup string, skipping occurrences 2…N before
`parseQTag()`. No new storage, no invalidation question, no staleness risk.

The `runlast` / `showtag` / `highlight` branches still decide per string, which
is safe because identical strings take identical branches. Checked before
shipping: `Text::build()` was the only `build()` override in either quanta or
the hili site, and `parseQTag`/`buildQTag` are pure — so skipping a duplicate
construction cannot lose a side effect.

**It catches less than the dev-page counters implied.** On the local page it
removed 19725 of 109799 constructions — 18%, not the ~58% the 8450/2470 ratio
suggests. The reason: most repeats are not *within* one `checkCodeTags` call but
*across* the passes `transformCodeTags` makes as rendered qtags emit new ones,
and each pass starts with a fresh `$seen`.

### Step 2b — extend the dedup across passes — BUILT, MEASURED, REJECTED

The obvious follow-up: a markup-keyed html memo on the Environment, sitting in
front of the Qtag cache (same request scope, same "same input, same output"
assumption, just in a currency that does not cost a serialization to mint).
Deferred `runlast` qtags excluded, since serving one early would render it at
the wrong point of the page.

It works exactly as designed — Qtag constructions 109799 → 47911, i.e. one per
distinct qtag, `cacheTag()` calls down 70%, per-request qtag-cache hits down to
**2** — and the render is byte-identical. And it is still not worth shipping:

| | `final` (steps 0–2) | + cross-pass memo |
|---|---:|---:|
| render p50 | 1488.5 ms (−6.1%) | 1471.3 ms (−7.2%) |
| PHP peak memory | 274.8 MB (−5.8%) | 325.3 MB (**+11.5%**) |

One point of render time for 50 MB of peak memory, on a page already at 275 MB
against a 512 MB `memory_limit`. Holding every distinct qtag's html for the
whole request is precisely what makes the answers available, so the memory is
not an implementation detail to tune away — storing the values bare instead of
in one-element wrapper arrays recovered 16 MB of 66 and no time. Reverted; the
finding is recorded as a comment at the call site so it is not rediscovered.

### Step 3 — a persistent store (APCu) — ANSWERED: NO

Step 2b is the strongest possible version of this idea: an in-request cache with
zero serialization cost, zero syscalls, and perfect invalidation (it dies with
the request). It bought ~1% of render time and cost a fifth of the page's memory
headroom. A persistent store is the same trade *minus* the free invalidation and
*plus* serialization on every get and put — and §5's dependency problem on top.

The reason is visible in the counters: after step 2, what remains is 47713
genuinely distinct qtags rendered exactly once each. There is no longer a
population of cheap repeats for a cache to absorb; the remaining work is the
renders themselves. A faster store does not make a render happen less often.

If page-level caching is wanted, the nginx FastCGI micro-cache (`QHTML`) already
exists and covers anonymous traffic at page granularity.

---

## 4. Why NOT files-db for the qtag cache

Considered and rejected as the **store**.

- **Data-model mismatch.** files-db indexes *nodes*: a directory with a
  globally-unique name, a father, links, per-language documents. A qtag entry is
  an opaque `crc32 → HTML blob` — derived output, not content. It would have to
  become a globally-name-unique node.
- **Index cost.** 16515 nodes in 31 MB of a 64 MB segment today. One page has
  2470 distinct qtags on dev and 47713 locally; site-wide that is tens of
  thousands of entries, doubling or tripling the index with non-content. Every
  `QuantaDb::path()` probe pays for it, and the segment budget tightens (cf. the
  `/dev/shm` SIGBUS history).
- **The write path is exactly what was just removed.** A files-db write is
  flock + tmp + fsync + atomic rename + UDS notify + *wait for the daemon ack*:
  26 ms typical, 729 ms worst, and a missed `write_ack_timeout_ms` flips every
  worker to fallback. HILI-379 deleted one such write per render and got 22%
  back. A cold qtag cache would add one per miss — thousands on a first render.
- **Wrong guarantee.** files-db exists to give read-your-writes with files as the
  source of truth. A render cache is purely derived and nobody needs that; it
  would pay full coordination cost for an irrelevant property.

**Where files-db does fit: as the invalidation signal.** `QuantaDb::meta($name)`
already returns `generation` and `mtime` per node. If a qtag recorded which nodes
it read during render, the cache key could include those generations and entries
would self-invalidate on content change. That is a legitimate use of the
extension — and dependency tracking is the actual work.

---

## 5. The hard part nobody has done: invalidation

The deleted branch did:

```php
if (is_file($qtag_cache_file)) { $this->html = $json->html; }   // forever
```

No invalidation of any kind. A rendered qtag depends on the template, the node
data it read, the current user's access rights, the language, and sometimes the
clock (this page renders `17/08/2026 19:15` timestamps). None of that is in the
key, which is only tag + attributes + target.

Persisting it across requests would serve one user's HTML to another, one
language's to another, and stale timestamps indefinitely. **Storage is the easy
half.** Any persistent qtag cache must define its key over the full dependency
set first; a faster store only makes a wrong answer arrive sooner.

---

## 6. Measured outcome

Local k3s cluster (`hili.local.it`), one pod, no CPU quota, 32-core host,
`performance` governor. The hili image is built on a quanta base image built
from this repo, one per variant:

```
docker build -t quanta-base:<v> <quanta worktree>
docker build --build-arg QUANTA_BASE=quanta-base:<v> -t hili:qtag-<v> ../hili
```

Target `/it/adm-jobs-list/` as an admin, 30 sequential requests after 5 warm-ups.
Every measured request carries a session cookie, so nginx's `QHTML` micro-cache
is bypassed (`X-Quanta-Cache: BYPASS`, asserted per request) and each one is a
full PHP render. Render time and peak memory come from php-fpm's own access log
(`%{mili}d`, `%{kilo}M`); CPU comes from php-fpm's `getrusage` (`%{user}C`,
`%{system}C`). Harness: `hili/scripts/bench-qtag.sh`, counters:
`hili/scripts/bench-qtag-counters.py`. Run-to-run noise on unchanged code is
0.1–0.3% on render p50/mean, so everything below is signal.

| | baseline | + step 0 | + steps 1&2 |
|---|---:|---:|---:|
| render p50 | 1584.7 ms | 1517.4 ms (−4.2%) | **1488.5 ms (−6.1%)** |
| render mean | 1579.9 ms | 1517.1 ms (−4.0%) | 1492.6 ms (−5.5%) |
| render p90 | 1611.3 ms | 1568.4 ms (−2.7%) | 1524.7 ms (−5.4%) |
| client TTFB p50 | 1574.9 ms | 1507.1 ms (−4.3%) | 1478.9 ms (−6.1%) |
| CPU per render (user) | 1247.8 ms | 1201.3 ms (−3.7%) | 1180.1 ms (−5.4%) |
| CPU per render (system) | 311.8 ms | 295.8 ms (−5.2%) | 294.3 ms (−5.6%) |
| CPU per render (total) | 1559.7 ms | 1497.0 ms (−4.0%) | **1474.4 ms (−5.5%)** |
| PHP peak memory | 291.6 MB | 274.8 MB (−5.8%) | **274.8 MB (−5.8%)** |
| throughput, 4 concurrent | 2.30 req/s | 2.35 req/s (+2.5%) | 2.40 req/s (+4.5%) |

What the render actually does, per request:

| | baseline | + step 0 | + steps 1&2 |
|---|---:|---:|---:|
| qtag occurrences in the html | 109799 | 109799 | 109799 |
| Qtag objects built | 109799 | 109799 | 90074 (−18.0%) |
| `cacheTag()` calls | 157286 | 109603 (−30.3%) | 89878 (−42.9%) |
| bytes json_encoded for keys | 103.7 MB | 53.6 MB (−48.3%) | **52.5 MB (−49.4%)** |
| distinct cache keys | 47740 | 47713 | 47713 |
| per-request cache hits | 61878 | 61890 | 42165 |
| actual renders | 47725 | 47713 | 47713 |

The dev-cluster prediction for step 0 (−48% of bytes serialized) reproduced
exactly at a 20× larger page size.

### Under the dev cluster's CPU limit

The numbers above are from an unthrottled pod, which is the clean measurement:
wall time is real work. Dev runs `limits.cpu: 450m`, where the render does not
fit in its quota and most of the page is CFS stall. Repeating the two ends with
`BENCH_CPU_LIMIT=450m` (20 requests, no concurrency pass):

| | baseline | + steps 0–2 |
|---|---:|---:|
| render p50 | 3698 ms | 3403 ms (−8.0%) |
| render mean | 4605 ms | 4284 ms (−7.0%) |
| render p90 | 4493 ms | 4244 ms (−5.5%) |
| throttled periods | 924 | 860 (−6.9%) |
| time spent throttled | 48.6 s | 44.6 s (−8.2%) |

The win is *larger* under the limit than without it, which is the expected
shape: work removed from a throttled process removes stall as well as CPU. It
does not make the page fit in 450m — §7's first two entries are what would.

**The render is unchanged.** The response body is 5 634 786 bytes in every
variant, and its sha256 with volatile bits masked (`dd/mm/yyyy hh:mm`
timestamps, asset cache-busters, tokens) is `1169a440eaac…` for baseline, step 0,
steps 1&2, both rejected step-2b variants, and both CPU-limited runs — and equal
to the image that was running before this work started.

---

## 7. Related, explicitly out of scope here

- **CPU limit.** 220 ms of the 370 ms dev page is CFS throttle stall (100% of
  periods), `limits.cpu: 450m` against a namespace quota of 2. Deliberately not
  changed. `hili/scripts/bench-deploy-variant.sh` takes `BENCH_CPU_LIMIT=450m`
  to reproduce that shape locally.
- **Pagination of `adm-jobs-list`.** The page renders every job unpaginated —
  265 on dev, ~4990 locally; every cost in this document scales with that count.
  Owner-decided, not planned here. It is by far the largest lever available on
  this page.
- **Retiring the nodePath symlink cache.** `Environment::nodePath` does
  `readlink` + `is_dir` on a shard symlink *before* asking `db()->path()`, which
  answers from a hash probe with zero syscalls. A prototype reordering cut a
  further 1381 syscalls (−8 ms) with a byte-identical render, but it is a real
  refactor: `FileFactory.class.php:26` and `FastDirList.class.php:98` read that
  cache directly and would need their own paths.
- **`cacheTag()` itself.** After step 2 it is called once per distinct qtag —
  the floor for this design — but each call still json_encodes the whole
  attribute array and target, 52.5 MB per render. The markup string that
  `checkCodeTags` already holds *is* that identity for every qtag that does not
  mutate itself in `render()`. Using it as the key is the next real lever, and
  unlike step 2b it would *remove* state rather than add it.
