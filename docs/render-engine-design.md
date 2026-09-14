# Render engine design — compiled templates in the segment, rows in Rust

Status: **design. Nothing here is implemented.** This document is the successor
to [template-engine-investigation.md](template-engine-investigation.md) (the
measurements) and [qtag-cache-plan.md](qtag-cache-plan.md) (the caching
verdicts): it turns their findings into one architecture and a phased roadmap.
Numbers are cited from those documents, not re-measured here.

Decisions already made by the owner and treated as constraints throughout:

- **The no-extension fallback stays byte-identical.** The three-mode CI
  contract (`noext` / `fallback` / `daemon`, `qdb/tests/run-quanta-tests.sh`)
  extends to everything below; an extension problem must never be worse than
  not having the extension.
- **The `qdb/` rename is deferred** until templates actually land in the
  extension (Phase 6). The runtime names are already `quanta_db`; only the
  directory and docs would move.

---

## 0. What we know going in

Four facts from the prior investigations bound this design:

1. **A compiled engine works and is fast.** Template text parsed once into an
   op program, executed many times: 3.3× in PHP, ~20× executed in Rust, output
   byte-identical (investigation, headline table). 92.6% of a qtag "render"
   today is ceremony — object construction, cache keys, hook dispatch — not
   the `render()` body (investigation §2).
2. **The engine is not the page.** On the reference page (4,990 rows, 5.63 MB,
   1488 ms), scan + substitution + ceremony is ~9%; a compiled engine removes
   ~6 points of it (investigation §6). The other ~90% is the PHP row pipeline:
   node build ceremony, access checks (17% of a node load, 63% of that PHP
   above the extension), template stat storms (~12 `file_exists` per lineage
   level per rendered node), per-row `transformCodeTags`, and each document
   loaded ~4× per render (`simpler-faster.md` §9.5).
3. **The extension was never the bottleneck.** Measured read time 3.3 ms inside
   a 1,498 ms render — 0.2% (`simpler-faster.md` §9). The segment, the
   pre-decoded images, the zero-copy `zend_string`s are already cheap; the
   cost is what PHP builds on top.
4. **A persistent rendered-HTML cache is a dead end.** A rendered qtag depends
   on user access, language *and the fallback actually used*, the request
   path, `[VARIABLE]` state, `attributes=` injection, and sometimes the clock
   (qtag-cache-plan §5). Storage is the easy half; nobody has done the
   invalidation, and this design does not try. **A compiled program has none
   of those dependencies** — it is a pure function of the template file — and
   that asymmetry is the whole design.

---

## 1. Architecture

**The Rust crate becomes the template authority. The existing fixpoint engine
becomes the permanent fallback and the semantic oracle.**

### 1.1 One compiler, in Rust

A new crate module, `qdb/src/template.rs`, parses template text into an
op program:

| op | meaning |
|---|---|
| `Lit(text)` | literal HTML |
| `Var(slot)` | bound variable — `[LISTITEM]`, `[LISTCOUNTER]`, `[LISTNODE]`, `[NODEITEM]` become slots, not `preg_replace` passes |
| `Tag{class, attrs, target, dyn_flags}` | a qtag call site; attrs parsed once at compile time |
| `Include(resolve + call)` | a sub-template call with arguments — retires the *only* reason the fixpoint loop exists (includes are textual today, investigation §1) |
| `Callback(site)` | "build the PHP object and run the full preload/load/render lifecycle" — the escape hatch for everything dynamic |
| `Deferred(literal)` | a `runlast` site: re-emit the original tag text for the existing second pass — byte-identical by construction, no new machinery |

The module lives in the crate — not the daemon binary — so both artifacts
share it the way `shm.rs`/`image.rs` are shared today (source include):
`qdbd` compiles on the boot walk and on inotify events, and the extension
exposes `QuantaDb::compileTemplate()` for tooling and tests.

**There is no PHP twin of the compiler, and that is deliberate.** The fallback
for "no program available" — no extension, incoherent index, unknown op
version, template not yet compiled — is today's fixpoint engine, unchanged.
That engine is simultaneously the `noext` implementation and the CI oracle:
every template rendered through both paths must hash identically, which turns
"is the compiler semantics-preserving?" from a discipline problem into a test
that already runs on every commit. Maintaining a second compiler in PHP would
add exactly the drift risk the oracle exists to catch.

### 1.2 Programs are segment records

A new record kind in the data segment, key-namespaced (`tpl!<relpath>`) so the
existing hash-probe path in `shm.rs` is untouched; a kind flag in the record
header for hygiene. Each record carries:

- the source file's mtime and size (staleness check),
- a **compiler version byte** — an extension older than the program refuses it
  and falls back; never worse than no extension,
- the op stream, encoded with the existing `image.rs` encoder against the
  segment-wide string table. Literal runs dedup across templates for free
  (production string-table dedup measured at 89%, `simpler-faster.md` §5.1).

Budget: hundreds of templates × a few KB against a 64 MB segment — sub-MB,
a non-issue. Invalidation is the invalidation the segment already has:
append a new record, publish, epoch/generation as today.

The plumbing for updates exists and is currently thrown away: `qdbd`'s
directory-level inotify watches already deliver `tpl.html` writes to the
dispatcher (`qdb/src/bin/qdbd.rs`, ~line 1240), which routes them to
`rescan_membership` and discards the content. Compilation is a new arm in
that dispatcher, not new plumbing.

### 1.3 Template resolution moves into the segment — as a live walk

`QuantaDb::resolveTemplate(node)` replays `NodeTemplate::buildTemplate`'s
candidate ladder — `tpl.html`, `_tpl/<name>_tpl.html`, `tpl-.html` …
`tpl-----.html`, `tpl^.html` — over the father chain, which the segment
already indexes. Twelve probes × lineage depth against shm hash lookups is
nanoseconds; the ~12 real `stat()`s per lineage level per rendered node,
uncached, on every render, disappear.

Deliberately **not** a precomputed per-node "effective template": adding or
removing one `tpl.html` along a lineage would invalidate a subtree of derived
records, and that fan-out buys nothing when the live walk is already free.

### 1.4 Execution, in two stages

**(a) PHP interpreter over shm-resident programs.** A small class in
`src/modules/qtags/` executes a program: emit `Lit`s, bind `Var`s, call
`Include`s, and for every `Tag`/`Callback` site build the Qtag object and run
the full lifecycle exactly as today — hooks included. What disappears for a
compiled fragment: the per-request parse, the page-wide regex scan, the
per-distinct-qtag `str_replace` over the whole string, and the fixpoint
re-scan of include output. What is preserved bit-for-bit: qtag semantics,
because the object path *is* today's object path.

**(b) Rust row execution.** `QuantaDb::renderList(tpl_key, rows, ctx,
callable)` runs a data-only row program entirely in Rust — children, documents
(already pre-decoded images in the mapping), program per row — returning the
concatenated HTML. `Callback` sites bounce to PHP via `ZendCallable`, the
mechanism `QuantaDb::update()` already ships (`qdb/src/lib.rs:2425`).
This is the measured 20× prototype (investigation §4a), and it is the stage
that attacks the ~90%: it never builds a `Node`, a `NodeAccess`, or a `Qtag`
for a data-only row. Access is per-row via Phase 4, or caller-gated until
then.

### 1.5 Dynamic sites are compile-time flags, conservative by default

The compiler consumes a **qtag manifest** — JSON generated at image build by a
`doctor` command reflecting over the 142 `*.qtag.php` classes, classifying
each as pure / pure-with-deps / must-stay-PHP (the classification from the
investigation holds: `Title`, `Attribute`, `HtmlTag`, icons… are pure
projections; `Link` depends on the request path and `hook('link_alter')`;
forms, session, user, `Variable`, `Css`/`Js`/`Messages` accumulators stay in
PHP forever).

Syntactic dynamism is flagged regardless of class, because it is hook
behavior, not class behavior:

- `attributes=<node>` — `qtags_qtag_preload` injects a node's JSON into the
  attributes at render time,
- `filter=` / `filter_node=` / `filter_key=` — `access_qtag_preload`,
  per-user,
- `grid=` — `grid_qtag` wraps *any* qtag's output post-render,
- `runlast` — becomes `Deferred`.

Unknown class, missing manifest entry, unparseable construct → `Callback`.
The conservative default is always correct, only slower — the manifest can
never cause a wrong page, only a less-compiled one.

### 1.6 The caching verdict

The owner's hypothesis — "caching maybe not even needed for templates/qtags"
— is **confirmed for templates**: the shm program *is* the cache. It is
compiled once per change (inotify), shared by every worker (one copy per
host), and carries none of qtag-HTML's invalidation dependencies. On compiled
paths there is no `cacheTag()`, no `json_encode` key ceremony, no markup memo
— the identity work vanishes rather than getting cheaper, which was
qtag-cache-plan §7's endpoint all along.

What remains, explicitly:

- **The per-request markup memo** (working-tree steps 0–2) stays for the
  uncompiled/dynamic path. When measurement shows the uncompiled path is
  <1% of a render, it is deleted (Phase 6).
- **A per-request `Node::loadJSON` memo** is still wanted — compiled programs
  don't remove the 4×-per-render document loads (22,917 loads for 5,755
  distinct nodes, `simpler-faster.md` §9.5). It needs explicit busting on the
  `FilesDb` write entry points, because node reloads after a write are
  semantically load-bearing; that is a contained, PHP-side change.
- **Persistent rendered-HTML caching stays rejected** (qtag-cache-plan §3–§5).
  The nginx FastCGI micro-cache already covers anonymous traffic.

---

## 2. Roadmap

Each phase ships alone, behind the `FilesDbExt` per-call-fallback pattern
(`src/modules/environment/classes/Common/FilesDbExt.class.php`), with a
before/after on the reference page and the 5000-row bench, and the masked
sha256 unchanged (except where flagged).

**Phase 0 — land what's proven. No Rust. — IMPLEMENTED**
The working-tree qtag-cache steps 0–2 (−6.1% p50, measured) stay as they are,
pending commit. Implemented on top:

- ~~`strtr` batch substitution in `transformCodeTags`~~ — **implemented, then
  reverted twice: it is a large regression, and investigation §9 step 1 should
  be struck.** First measurement, `hili/docs/perf/dev/README.md` §6: +34% on a
  325 KB page, attributed to per-call setup against small per-row subjects.
  That attribution predicts a big enough subject flips the trade, so it was
  tested directly here — `strtr` for whole-page passes only, on a 6.6 MB page —
  and it came out **+52.6% (685.7 → 1046.6 ms)**. Worse, not better.
  The variable is not subject size but the number of **distinct key lengths**:
  `strtr` probes every position for a key of each length it knows, and a page
  with tens of thousands of distinct qtag markups has thousands of distinct
  lengths, so it degenerates while `str_replace` stays a straight scan per key.
  The investigation's §5 benchmark used 10–120 distinct keys, which is why it
  looked good there and nowhere since. Both results are recorded at the call
  site. (Correctness was never in question — 3,748 randomized render tables,
  zero output mismatches. It is simply slower.)
- `MAX_TRANSFORM_PASSES = 100` bound on the fixpoint loop (CODE-REVIEW 1.28).
  The counter existed from the start and was never read; reading it turns a
  self-referencing Qtag from a request killed by `max_execution_time` into a
  page that renders with visible markup and a warning.
- `Qtag::load()` no longer blanks the HTML of an already-rendered object
  (1.4) — `__toString()` calls `load()` unconditionally, which made printing
  a Qtag `preload()` had already rendered return the empty string.
- `NodeAccess`: the actor is now part of both the static cache key and
  `cacheTag()` (1.29), and both hashes moved crc32 → xxh64 (3.5) — a 32-bit
  space is not where an access verdict should be decided. Also fixed:
  `getPermissions()` ran *before* the `!is_object($this->node)` guard that
  exists to protect it, so a non-object node fataled instead of being denied.

*The body-injection item is deliberately NOT addressed here.* Filtering
`Body::render()` outright is not available: **Qtags in node bodies are a
supported feature** — `profiles/generic` itself ships `[LINK:…]` and
`[ADD:…]` inside body fields — so an unconditional filter breaks the profile
in this repo and any site using the pattern. A global opt-in switch was
prototyped and then dropped: off, it is dead weight on every site; on, it
breaks the generic profile. That the safe setting is the useless one is the
signature of a control at the wrong granularity — the lever belongs per-field
or per-role, not container-wide, and `[BODY|no_qtags]` already covers the
per-call case. It is also worth being precise about scope: neutralizing Qtag
delimiters would not answer the standing `TODO: breaks the HTML, but we
definitely need a xss filter for this tag` in `Body.qtag.php`, which is about
script, not markup. Both remain open, and both want their own change with a
decision on defaults. The structural fix for the Qtag half is Phase 3's:
emitted values are data unless a call site asks for template compilation.

**Phase 1 — profile, then reduce the redundant loads. — PARTLY IMPLEMENTED**
The profile is still owed: flamegraph the real 1488 ms page before spending
further budget (investigation §6, §9 step 2). It needs a running site, so it
belongs to whoever has one.

The load reduction landed, but **not as the per-request `loadJSON` memo this
plan assumed** — that design does not survive contact with the code. A memo
keyed on name/lang/path must hand the same decoded document to every caller,
and `$node->json` is mutated in place across the codebase
(`setAttributeJSON`, `removeAttributeJSON`, `Node::updateJSON()`, and
`NodeFactory::duplicate()` assigning `$new_node->json = $source_node->json`).
Sharing one object between nodes lets those writes leak sideways; handing out
a deep copy instead costs more in PHP than the ~0.5 µs segment probe it
avoids (`bench5`, investigation §4). Either way a bad trade — presumably why
`simpler-faster.md` §9.5 said this "needs its own invalidation design, not a
cache slapped on the getter".

The real redundancy is narrower and raises no aliasing question at all:
**the duplicate loads are on the same object.** `Node::__construct()` ends
with `$this->load()`; `NodeFactory::load()` then calls `$node->load()` again
against identical state; and when the node has no document in the requested
language it calls `setLanguage($fallback)` and `load()` a third time. So
`Node::load($force = FALSE)` now records a signature of the language and path
that `buildContent()` last ran against, and declines to redo work *this
object* has already done. The third call has genuinely different state and
still runs. `Node::save()` clears the signature, so "reload after a write"
still means a real reload. Nothing is shared between nodes, so nothing can
alias.

Watch out: `User::load()` overrides `Node::load()` and had to take the new
optional parameter — without it PHP refuses to link the class at all, on
every request that touches a user.

**Measured on the real page — this is the largest win in the whole plan.**
On local k3s, `/it/adm-jobs-list/` (4,990 rows, 6.65 MB body, N=30, no CPU
limit), against the tree that already carried qtag-cache steps 0–2:

| | before | after | |
|---|---:|---:|---:|
| render p50 | 1348.4 ms | **846.6 ms** | **−37.2%** |
| cgroup CPU / request | 1478 ms | 850 ms | −42.5% |
| `c=4` throughput | 2.52 req/s | 3.91 req/s | +55% |

and against pristine `HEAD`: render p50 1488.8 → 846.6 ms (**−43.1%**), CPU per
request −47.5%, PHP peak memory −2.6%, container memory peak −8.8%, throughput
+68.6% — with the **masked body sha256 identical**, so the rendered output does
not change. Full table: `hili/docs/perf/qtag/render-phases.md`.

For context, the qtag-cache steps 0–2 already in the tree are worth −9.4% of
that total; this change is the other −37%.

**Phase 2a — template resolution: stop re-asking the filesystem. — IMPLEMENTED**
Most of Phase 2's win is available in PHP before any of it moves to the
segment. `NodeTemplate::buildTemplate()` probes a fixed ladder of candidate
files at every level of a node's lineage, on every render, uncached — and the
rows of a list are siblings, so they share every ancestor above them and
re-ask byte-identical questions thousands of times per page. Those
`is_file()` / `file_exists()` calls are now memoised for the request
(`isFileCached()` / `fileExistsCached()`, kept separate so each call site
keeps asking exactly the question it asked before). Templates are not created
mid-request, so an answer cannot go stale inside one.

The sub-level ladder also stopped looping `i = 1..5` only to act on the
iteration matching the current sublevel; it computes the dash count directly.
Verified equivalent over 5,400 randomized on-disk layouts (1,787 of which
resolved to a template), including directories planted where a template file
would be so that `is_file` and `file_exists` disagree: **zero mismatches**.

**Measured: −0.1% on the reference page, i.e. nothing — and that is expected
rather than a refutation.** A list renders its rows through an explicit module
template, which hits priority 1 in `buildTemplate()` and returns *before* the
lineage ladder runs at all, so this page never pays the stat storm this fixes.
The change is kept (it is a memo and a simplification, and the storm is real on
pages that render many node templates), but it is **unproven**, and proving it
needs a page shaped to exercise it. Worth knowing before Phase 2b spends Rust
effort on moving the same resolution into the segment: on this workload, that
move has no measured cost to recover.

**Phase 1b — the profile, and what it found. — IMPLEMENTED**
The profile the plan kept owing itself now exists: Excimer at 1 kHz over 20
authenticated renders on local k3s, aggregated from collapsed stacks. Build the
profiling image by layering Excimer onto the app image; it samples only when a
request carries `X-Profile: 1`.

Self time on the 846 ms page, after Phase 1:

| ms/req | % | frame |
|---:|---:|---|
| 203.9 | 23.8% | `QtagFactory::transformCodeTags` (156 of it the whole-page passes, 48 per-row) |
| 104.0 | 12.1% | `Environment::hook` (65 of it `node_build`, 19 the two hooks per qtag) |
| 87.5 | 10.2% | `QtagFactory::checkCodeTags` |
| 74.0 | 8.6% | `DataContainer::setData` — **94% of it from `stats_node_build`** |
| 40.6 | 4.7% | `QtagFactory::parseQTag` |
| 37.8 | 4.4% | `Cache::get` |
| 34.0 | 4.0% | `FilesDbExt::load` |

Two things to take from it. First, **the engine is ~40% of self time**, not the
~9% §6 of the investigation estimated — that estimate was made when node
loading dominated a 1488 ms page, and removing the node work raised the
engine's share. The case for Phase 3 is stronger than the plan assumed, and
`Environment::hook` at 12% is precisely the per-qtag ceremony a compiled engine
removes. Second, the single biggest item was not the engine at all:

**Phase 1c — `stats_node_build` appends in place. — IMPLEMENTED**
It read the node list out of the Environment, appended one entry, and wrote it
back, so PHP copy-on-wrote the whole growing list on every node build — O(n²)
on a page building thousands of nodes, for a debug panel. `Cache::set()`
already carries a comment about this exact trap; this was the same bug in its
other home. Now appends in place; the `[STATS]` panel is unaffected.

Measured: **845.9 → 684.1 ms, −19.1%**, output unchanged.

**Cumulative, pristine `HEAD` → now**, `/it/adm-jobs-list/`, N=30:
render p50 **1488.8 → 684.1 ms (−54.1%)**, cgroup CPU/request **−57.2%**,
`c=4` throughput **+100.4%**, PHP peak memory −2.6%, container memory peak
−8.8%. The 6.65 MB body differs from baseline on exactly one line: the line
*number* in a pre-existing `Undefined array key 1` warning that `Api.class.php`
renders into the page (its UA parser reads `$matches['version'][1]` when one
version matched), shifted because this work added lines to that file. Worth
fixing on its own account — it puts a PHP warning in the response body.

Full tables and the rejected variants: `hili/docs/perf/qtag/render-phases.md`.

**Phase 3a — substitute in one pass over the subject. — IMPLEMENTED**
The re-profile after Phase 1c put the engine at **57% of self time**, with
`transformCodeTags` alone at 193 ms/request — because it rendered every distinct
Qtag and then ran a `str_replace` over the *whole subject for each one*:
O(distinct × subject), i.e. a 6.6 MB page rebuilt once per distinct Qtag.

`preg_replace_callback` walks the subject once per delimiter and splices as it
goes, so the cost stops depending on how many distinct Qtags a page contains.
The per-markup resolution (memo → parse → runlast/showtag/highlight/render) moved
into `QtagFactory::resolveMarkup()`, shared by the callback and by the now-thin
`checkCodeTags()`.

Two details worth keeping:
- "Leave this markup alone" (deferred `runlast`, or a string that is not a Qtag)
  needed to become distinct from "this Qtag rendered NULL", which substitutes an
  empty string. They were conflated in the memo before, which is why a
  NULL-rendering Qtag was never memoised and re-rendered on every later pass.
  `MARKUP_UNRESOLVED` is now the sentinel and NULL results memoise properly.
- Rendering order is unchanged — the callback fires in scan order, exactly the
  order the old code rendered in — and replacement text is not re-scanned by
  `preg_replace_callback`, so Qtags revealed by a substitution are still picked
  up by the next turn of the fixpoint loop.

Measured: **684.1 → 507.2 ms, −25.9%**, PHP peak memory −0.7%, `c=4` throughput
4.65 → 6.36 req/s.

Note this removes the *substitution* half of the fixpoint cost, not the scan
half: `checkCodeTags`'s repeated `preg_match_all` over the whole subject is still
there (80 ms/request at last profile). Scanning only replacement values — new
Qtags can come from nowhere else — is the remaining structural fix, and it is
what Phase 3's compiled `Include` op does properly.

### Cumulative, pristine `HEAD` → now

`/it/adm-jobs-list/`, N=30, no CPU limit:

| metric | `head` | now | |
|---|---:|---:|---:|
| render p50 | 1488.8 ms | **507.2 ms** | **−65.9%** |
| cgroup CPU / request | 1618 ms | 514 ms | −68.2% |
| `c=4` throughput | 2.32 req/s | 6.36 req/s | **+174%** |
| PHP peak memory | 330 168 KB | 319 380 KB | −3.3% |
| container memory peak | 1578 MB | 1424 MB | −9.8% |

Output verified unchanged across **8 different pages** (`/it/`, the six admin
pages, `my-profile`), byte-for-byte once two per-deployment values are masked:
the asset cache-buster timestamp, and the line *number* inside the pre-existing
`Api.class.php` warning.

**Phase 3b — compiled programs executed in PHP. — BUILT, MEASURED, REJECTED**

This is the plan's centrepiece and it does not work in PHP. Recording it in
full, because the roadmap was built on a number that does not survive contact
with the real workload.

*What was built.* A `QtagProgram` compiler and executor: template text → ops
(`Lit` / `Var` / `Tag`), compiled once per template, executed once per row,
wired into `DirList::generateList()` — the right target, since **69% of the
page renders inside that loop** and every row is the same template with
different values substituted in.

*Fidelity first, and it held.* The compiler round-tripped **270 of 270** real
templates in quanta and hili byte-for-byte (compile → decompile → compare), and
the rendered page hash never changed across any variant. Getting there surfaced
four constraints that any future compiler — Rust included — has to honour, none
of them obvious:

- **The runtime parser never sees nested markup.** `parseQTag()` splits the
  attribute section off at the first `:` and then on every `|`, because by the
  time it runs, inner Qtags are already rendered text. Parsing nested *source*
  directly splits differently wherever rendered inner text contains `:` or `|`.
  A compiled call site therefore cannot just hand over its parsed pieces; it
  has to agree with flat parsing, or prove the difference cannot arise.
- **The scanner's character class excludes every bracket**, so a Qtag whose
  markup still contains one is never matched — it stays in the output as text,
  forever. Rebuilt markup has to be checked for that, or the compiler resolves
  call sites the engine deliberately leaves alone.
- **`{LISTITEM}` is not a variable.** `DirList` substitutes `[LISTITEM]` with a
  square-bracket-only `preg_replace`, so the brace form reaches the scanner as
  a Qtag and renders as one. The carousel and gallery templates rely on this.
- **That substitution is case-insensitive**, so `[listitem]` is a variable too,
  even though the scanner would never match it as a Qtag.

*What it measured.* Against a 507.2 ms base, three increasingly optimised
versions:

| version | render p50 | vs base |
|---|---:|---:|
| rebuild flat markup, then resolve as usual | 524.6 ms | +3.4% |
| + build the Qtag directly from compiled pieces | 559.7 ms | +10.3% |
| + pre-flatten static attributes and targets at compile time | 554.5 ms | +9.3% |

Output identical in all three.

*Why.* The thing compilation removes — re-discovering structure — is done by
`preg_replace_callback` and `explode`, which are C. The thing it adds — walking
an op tree, per part, per call site, per row — is userland PHP. On this
template the second is simply more expensive than the first, and no amount of
pre-flattening closes the gap because the remaining cost is the Qtag object and
its two hook dispatches, which compiling does not touch.

*Consequence for the roadmap.* The investigation's "compiled program, still
PHP: 3.3×" (§9 step 4) came from a synthetic harness with a much simpler
template; it should be struck, as §5's `strtr` recommendation already was.
**There is no cheap PHP half of Phase 3.** A compiled program only pays once
its execution is native, which is Phase 5 — so the plan's incremental
stepping-stone does not exist, and getting the compiler's win means committing
to Rust execution directly rather than proving it in PHP first.

The remaining per-qtag cost is worth restating, since it is what Phase 5 would
have to beat: ~80 ms/request of parse-and-build, ~61 ms of hook dispatch, ~46 ms
of cache lookups, ~75 ms of callback dispatch.

**Phase 2b — template files and resolution into the segment.**
`qdbd` stops discarding tpl inotify events; indexes template presence, source
and mtime as the new record kind on boot walk and change.
`QuantaDb::resolveTemplate()` + `QuantaDb::templateSource()`;
`NodeTemplate::buildTemplate` calls them through `FilesDbExt` with per-call
filesystem fallback. Expected: the template stat storm goes to zero; measured
by strace stat count and p50. Independently valuable even if compilation
never ships.

**Phase 3 — compiler + PHP interpreter.**
3a: the qtag-manifest doctor command, run at base-image build next to
`quanta-build-assets` (`docker/build-assets.sh` precedent), shipped in-image.
3b: `template.rs`; `qdbd` compiles on boot walk + inotify; version byte.
3c: the PHP interpreter; `NodeTemplate` and `[CONTENT:x]` includes become
program calls; `DirList` binds `[LISTITEM]`/`[LISTNODE]` as `Var` slots
instead of three `preg_replace`es per row. The fixpoint engine is untouched.
3d: CI — three modes byte-identical, plus a corpus check rendering every
site and module template through both engines and diffing.
Site-template compilation at downstream-image build is a *validation* step —
failing the build on template errors is the real value; compiling a few
hundred templates at daemon boot is milliseconds, so no artifact-stashing
plumbing. Module `*.tpl.php` files compile only if they contain no actual PHP
(verify during 3b; the ones that do stay on the fixpoint path).
Expected: 3.3× on the engine's share minus parse/scan entirely — a few
percent of the page, honestly framed — plus the load-bearing capability for
Phase 5, and compile-time template errors.

**Phase 4 — effective permissions in Rust.**
`QuantaDb::effectivePermissions(node, roles[])`: the `inherit` walk over
`json->permissions` up the father chain, both already in the segment
(investigation §4c). Replaces — not patches — the user-blind `NodeAccess`
cache from CODE-REVIEW 1.29. Per-call fallback to PHP `NodeAccess`.
Expected: access is 17% of a node load, 63% of it PHP above the extension;
and it unblocks Phase 5's full-Rust rows.

**Phase 5 — Rust row/fragment execution.**
`QuantaDb::renderList()` as in §1.4(b). This is the phase the whole roadmap
exists to make small: by now templates are compiled records (3), resolution
is a probe (2), permissions are a probe chain (4), and the escape hatch
(`Callback` via `ZendCallable`) is proven. Expected: the prototype's ~20× on
the row pipeline — the ~90% of the reference page.

**Phase 6 — deletions, then the rename.**
Delete on measurement: the `cacheTag()`/`json_encode` key machinery for
compiled paths, the markup memo if the uncompiled path is <1%, dead
interpreter branches. Then the mechanical `qdb/` → `quanta-db/` rename
in one commit (directory, docs, CI paths; the `.so`, ini keys, and crate are
already `quanta_db`). The PHP classes `FilesDb`/`FilesDbExt` are **not**
renamed — that churns the fallback plumbing, the most correctness-critical
code in the system, for zero behavior.

---

## 3. What gets simpler, what gets harder

Simpler:

- The fixpoint loop is retired from the hot path and frozen as the oracle —
  ~200 lines that stop evolving.
- Includes are calls with arguments; the "sub-template can't see
  `[LISTITEM]`" context-loss bug class (investigation §3) becomes
  inexpressible.
- Template resolution is one Rust function instead of stat ladders duplicated
  across `NodeTemplate` and conventions.
- Dynamic behavior is an explicit flag on a call site instead of emergent
  hook interplay.
- Template errors surface at image build, not as silently-empty output.
- Body-as-data closes the content-injection hole structurally.
- Real control flow (`if`/`for`/scoped `set`) becomes *possible* — out of
  scope here, but the op format is where it would live.

New complexity, contained:

- An op format to version — handled by the version byte and refuse-newer →
  fallback.
- One compiler whose correctness matters — handled by the oracle + corpus
  diff, infrastructure that already runs.
- The three-mode CI discipline extended to programs — an extension of an
  existing discipline, not a new kind of obligation.

## 4. Risks

| risk | mitigation |
|---|---|
| Compiler semantic drift from the PHP engine | fixpoint oracle; three-mode byte-identical CI; full-corpus diff render per commit |
| A hook the manifest can't see (site module registering `hook_qtag*`) | manifest regenerated every image build; unknown → `Callback`; the conservative default is correct, only slower |
| `ZendCallable` reentrancy (Rust→PHP→extension) | `QuantaDb::update()` precedent; add an explicit recursion test to `run-quanta-tests.sh` |
| Segment churn from template recompiles | records are tiny; append-only + epoch republish already absorbs it; watch `qdbstat` |
| `runlast` / accumulator ordering | `Deferred` re-emits the literal tag into the existing second pass — byte-identical by construction |

## 5. What NOT to do

- **Persistent qtag HTML cache** — invalidation dependencies adjudicated
  unresolvable (qtag-cache-plan §5).
- **The segment as a rendered-HTML store** — write-path cost and data-model
  mismatch, already rejected (qtag-cache-plan §4).
- **Precomputed per-node effective templates** — invalidation fan-out for a
  walk that is free over shm.
- **Compiling node bodies** — bodies are data; compiling them is the
  injection hole with extra steps.
- **A PHP compiler twin** — drift risk; the fixpoint engine is both fallback
  and oracle.
- **Whole-page Rust rendering** — sessions, messages, accumulators, `runlast`
  make it a parity swamp; fragments and rows only.
- **Moving forms/session/user qtags to Rust** — the must-stay-PHP
  classification is correct and permanent.
- **Fixing the unpaginated list here** — it remains the single largest lever
  on the reference page and it dwarfs everything in this document, but it is
  a product decision, not an engine one (qtag-cache-plan §7).
