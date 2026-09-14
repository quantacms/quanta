# Making the Qtag template engine compilable — investigation

Status: **investigation only. Nothing here is implemented.** Every number below
was measured; the harnesses are listed in §8 so they can be re-run.

The question: can Quanta's template engine become a *compiled* engine in the
Twig sense — template text parsed once into a program, executed many times —
and can the Rust `quanta_db` extension do part of that work?

Short answer: **yes to both, and the Rust half is worth far more than the
compilation half.** On a byte-identical 5000-row list render:

| | 5000 rows | µs/row | vs today |
|---|---:|---:|---:|
| today — substitution to a fixpoint | 118–121 ms | 24 | 1× |
| compiled program, still PHP | 35.6–36.0 ms | 7.1 | **3.3×** |
| compiled program, executed in Rust | 5.2–6.8 ms | 1.0–1.4 | **~20×** |

Same input tree, same qtag semantics, same `quanta_db` extension serving the
documents, output `sha256 67ecacae…` in all three.

But read §6 before planning on those numbers: on the page that motivated
[qtag-cache-plan.md](qtag-cache-plan.md), the engine is only ~8% of the render.
Compiling it is a real win on the part it owns and a large capability win — it
is not, by itself, the fix for that page.

---

## 1. What the engine does today

A qtag is `[TYPE|attr1=x|attr2=y:target]`. Rendering is textual substitution
over whatever string currently holds the markup
(`QtagFactory::transformCodeTags`):

```
while (TRUE):
    preg_match_all('/\[[A-Z][^\[\]{}]+\]/s', $html)   # scan the whole string
    for each distinct match: build a Qtag object, render it
    for each distinct match: $html = str_replace($markup, $out, $html)
    # repeat -- a rendered qtag can emit new qtags
```

Three consequences shape everything below.

**Includes are textual.** `[CONTENT:_header]` returns the *unrendered text* of
another template (`NodeTemplate::buildTemplate` does not transform unless a
`tpl` was passed). The qtags it carries are found by the *next iteration of the
loop*. That is the only reason the fixpoint loop exists.

**Context is passed by pre-substitution.** `DirList::generateList` does
`preg_replace('/\[LISTITEM\]/', $name, $tpl)` on the row text *before*
transforming it, because the grammar cannot nest and a qtag has nowhere to put
a variable. `NodeTemplate` does the same for `[NODEITEM]`.

**Data is re-scanned as template.** A node body arrives through `[BODY]` as a
plain string and lands in `$html`, so the next pass scans it for qtags. See §7.

---

## 2. What a compiled engine changes

Compile each template *once* into an op program, cache the program by template
identity, and execute it into a single output buffer:

```
Op::Lit(text)            append a literal
Op::Var(slot)            append a context value      ([LISTITEM], [LISTCOUNTER])
Op::Tag{site, target}    render a call site
Op::Include(program)     CALL another program        (no re-scan, no fixpoint)
```

Four costs disappear outright:

1. **The re-scan.** `preg_match_all` runs at compile time, once per template,
   not once per pass per rendered string.
2. **`str_replace` per distinct qtag.** Output is appended to one buffer.
3. **Per-occurrence object construction.** One `Qtag` instance per *call site*,
   hoisted to compile time; only the target changes per row.
4. **`cacheTag()`.** The tag name and the attribute array are compile-time
   constants, so the identity prefix is minted once. This is the "next real
   lever" [qtag-cache-plan.md §7](qtag-cache-plan.md) names — 52.5 MB of
   `json_encode` per render on the measured page — and compilation removes it
   as a side effect rather than as a separate optimisation.

Measured separately (`bench3.php`, 50 000 distinct qtags, all cache misses):

| | per qtag |
|---|---:|
| construct + `preload()` + `cacheTag()` + 2 hook dispatches | 0.90 µs |
| hoisted call site, identity precomputed | 0.14 µs |
| the `render()` body alone — the floor | 0.07 µs |

**92.6% of a qtag "render" is ceremony**, and hoisting removes 84% of it.

### What compilation does NOT remove

- A qtag whose *output* carries qtags and is not a template (a node body) still
  needs interpretation. That is correct and cheap: it is a small fragment, and
  the existing interpreter can stay as the fallback for exactly this case.
- Attributes injected at run time. `qtags_qtag_preload()` copies a node's whole
  JSON into `$qtag->attributes` when `attributes=<node>` is set. Those call
  sites must be marked dynamic at compile time and keep the per-render key.
- `runlast`. It needs a second execution phase, which the program can express
  as a deferred op list — cleaner than today's second full-page pass.

---

## 3. What compilation buys beyond speed

This is the stronger half of the case, and it does not depend on any benchmark.

**Includes stop losing context.** Today a sub-template pulled in by a qtag
cannot use `[LISTITEM]`: the row's `preg_replace` already ran, so the
placeholder arrives after its substitution point and renders empty (an
unmatched `[LISTITEM]` builds a generic `Qtag`, whose `render()` returns NULL).
A compiled include is a call with an argument, so the hole closes.

**Real control flow becomes expressible.** There is no `IF`, no `FOREACH`, no
scoped `SET` — 142 qtags and the only variable mechanism is `[VARIABLE|set=…]`,
which writes to `$env->data` globally and warns when set twice. Iteration only
exists inside PHP qtags (`ListNodes` → `DirList`). With an op program, `if` /
`for` / `set` are ops, and blocks and inheritance become possible at all.

**Errors move to compile time.** A malformed qtag today either renders empty or
is left in the page as literal text. A compiler can reject it once, at the
template, with a line number.

**The grammar's limits become fixable.** Delimiters are positional and there is
no escape: an attribute value cannot contain `|`, `=`, `:`, `[`, `]`, `{` or
`}`. `Api::string_normalize()` exists as the escape hatch but is opt-in per call
site and entity-encodes ordinary `:` and `|` in the output text. A real
tokenizer can support quoting without breaking existing templates.

**Data stops being template.** See §7.

---

## 4. Where the Rust extension fits

`quanta_db` is not just a JSON cache. `qdbd` holds the whole node tree — paths,
fathers, children, links and the *pre-decoded* documents — in a shared-memory
segment, and `image.rs` writes ready-made `zend_string`s so a PHP zval points
straight into the mapping with no copy and no `json_decode`. Measured against
the real extension on a seeded tree (`bench5.php`, 2000 nodes, daemon mode):

| | per node |
|---|---:|
| `QuantaDb::path()` — index probe | 0.19 µs |
| `QuantaDb::getRaw()` — zero-copy raw JSON string | 0.21 µs |
| `QuantaDb::get()` — whole document into zvals | 0.50 µs |
| + `Node` build, `applyJsonFields`, hooks, request cache | 1.24 µs |

So the data is already cheap. **The expensive part is everything PHP builds on
top of it** — and a qtag like `[TITLE:x]` or `[ATTRIBUTE|name=city:x]` needs one
field of it.

### 4a. The strong version: render data-only fragments in Rust

Most of a list row is a pure projection of documents that are *already parsed,
in shared memory, inside the extension*. Rendering the row there means the
document never becomes zvals at all, and no `Node`, `NodeAccess` or `Qtag`
object is ever constructed.

`rustproto/` is a working prototype: it compiles the same row template into the
same op program and renders 5000 rows over the same seeded tree.

```
(daemon-side, once) parsed 5000 docs in 31.9 ms     <- what qdbd already does
Rust pipeline: 5000 rows in 5.2 ms  (1.04 us/row)
PHP  pipeline: 5000 rows in 118.2 ms (23.6 us/row)
sha256: identical
```

The 31.9 ms parse is startup work the daemon already performs and is not per
request. Per request it is **5–7 ms against 118–121 ms**.

Two things make this credible rather than a toy: the output is byte-identical,
and the mechanism the result has to cross back on — handing PHP a string that
lives in the mapping — is the mechanism `image.rs` already ships.

### 4b. The cheap version: put the compiled program in shared memory

A compiled program is a nested array of literals and call sites: exactly the
shape `image.rs` already encodes and materialises. Published into the segment,
every worker gets the program with no parse, no `unserialize`, no APCu, and one
copy per host instead of one per worker. This is a much smaller change than 4a
and carries no semantic risk — the program is derived data, rebuildable, and
already invalidated by the same mtime/generation machinery as any node.

### 4c. Access control is the blocker for 4a, and it is also in the segment

Rendering a node in Rust means the PHP access check does not run. Skipping it is
not acceptable. But the check is derivable from data the segment already holds:
`Node::loadPermissions()` reads `json->permissions->{action}`, and on `inherit`
walks the lineage — and the segment indexes fathers. `QuantaDb` could answer
"effective permissions for node X" from one probe chain with no PHP objects at
all. Measured today (`bench6.php`, ancestors warm): the access and permission
machinery is 17% of a node load, and 63% of it is PHP above the extension.

That is a prerequisite, not a detail. **A Rust render path must resolve
permissions in Rust, or it must be restricted to fragments whose access was
already decided by the caller.**

### 4d. Escape hatch

Not every qtag can move. `Form`, `FormItem` (671 lines), `Img`/`Thumbnail`,
anything that touches the session, and anything a site module defines must stay
in PHP. The program therefore needs a `Callback(site)` op: Rust renders what it
knows and calls back into PHP for the rest (`ext-php-rs` can invoke a PHP
callable). A row that is entirely data-only takes the fast path; a row with one
foreign tag still avoids the scan and the substitution.

---

## 5. One lever that needs no compiler

`qtags_page_complete()` runs `transformCodeTags()` over the **whole final
page**, twice (once normally, once for `runlast`). `transformCodeTags` then does
**one `str_replace` over that whole string per distinct qtag** — each a full
scan and a full fresh allocation of the page. That is O(distinct × page).

`strtr($html, $replaces)` does all of them in one scan and one allocation, and
converges to the same fixpoint: a substitution that reveals new markup is caught
by the next pass of the existing `while (TRUE)` loop instead of within the
current one. Measured on a 5.34 MB page (`bench4.php`):

| distinct qtags at page level | `str_replace` loop | `strtr` | |
|---:|---:|---:|---:|
| 10 | 20.1 ms | 3.7 ms | 5.5× |
| 40 | 74.7 ms | 4.2 ms | 17.7× |
| 120 | 218.4 ms | 3.7 ms | 58.5× |

Output identical in every case. The win scales with how many distinct qtags
survive to the page-level transform — **which nobody has counted on a real
page, and should be counted before claiming a number.** At row level (1 KB
strings) it is a wash, so this is specifically about the whole-page passes.

It is a few lines in `QtagFactory::transformCodeTags`, keeping the existing
`NULL → ''` and `array → implode` normalisation, applied before the `strtr`
instead of inside the loop.

---

## 6. Honest sizing: the engine is not the page

On the page in [qtag-cache-plan.md](qtag-cache-plan.md) — 4990 rows, 5.63 MB,
109 799 qtag occurrences, 47 713 distinct, **1488 ms render** — the shape
matches this investigation's model closely (22 occurrences and ~9.5 distinct
qtags per row here, 22 and 12 in the harness). Attributing what was measured:

- engine scan + substitution + object ceremony: ~2.4 µs × ~55 000 ≈ **130 ms, ~9%**
- of which a compiled engine removes roughly two thirds: **~85 ms, ~6%**
- whole-list end-to-end in the harness: 120 ms for 5000 rows, **~8% of 1488 ms**

**The other ~90% is not the template engine**, and this investigation cannot say
what it is without a profile of that page. The candidates visible in the code:
`Environment::nodePath` doing `readlink` + `is_dir` before the index probe that
needs no syscall at all ([qtag-cache-plan.md §7](qtag-cache-plan.md) already
flags this); `NodeTemplate::buildTemplate` doing up to ~10 `file_exists` per
lineage level per rendered node, uncached, on every render; the heavy qtags
(`Link` 227 lines, `FormItem` 671); `Localization`; thumbnails.

So: **profile that page before spending the engine budget on it.** The engine
work is justified by §3 and by the 3–20× it delivers on the part it owns — not
by a promise to fix that page.

Note also what §4a's 20× really measures: the Rust prototype is fast partly
because it compiles, and mostly because it never constructs a `Node`, a
`NodeAccess` or a `Qtag` at all. That is the same ~90% named above, approached
from the other side.

---

## 7. A correctness finding worth acting on regardless

`Body.qtag.php` returns `$node->getBody()` raw — it carries its own `TODO: we
definitely need a xss filter for this tag` — and that string lands in `$html`,
where the next pass of the loop scans it for qtags. **Anything a content editor
types in square brackets is executed as template markup.** `[DELETE:some-node]`
in a node body renders a delete control; the qtag vocabulary is the whole
attack surface, bounded only by the access checks each qtag happens to make.

`Api::string_normalize()` is the existing defence, but it is applied per call
site (via the `no_qtags` attribute and a handful of explicit calls), not to
untrusted data by default.

A compiled engine fixes this structurally, the way Twig does: emitted values are
**data** unless a call site explicitly asks for them to be compiled as a
template. It is worth stating plainly that this is available today, without the
compiler, by filtering in `Body::render()` — the compiler makes it the default
rather than a thing each qtag must remember.

---

## 8. How the numbers were produced

Harnesses live outside the repo, in this session's scratchpad
(`~/.claude/tmp/claude-1000/-home-daylioti-adalot-quanta/5b8145b6-2d02-400f-aa27-6e1c03623852/scratchpad/bench/`),
all re-runnable:

| file | what it measures |
|---|---|
| `bench2.php` | substitution vs compiled program, synthetic, free qtag work — 3.0–3.15× |
| `bench3.php` | per-qtag ceremony: 0.90 µs vs 0.14 µs hoisted vs 0.07 µs floor |
| `bench4.php` | `str_replace` loop vs `strtr` on a 5.34 MB page |
| `bench5.php` | `quanta_db` boundary cost per node (path / getRaw / get / +Node) |
| `bench6.php` | access + permission machinery as a share of a node load |
| `bench7.php` | **end-to-end 5000-row list: today vs compiled PHP, real extension** |
| `rustproto/` | **end-to-end 5000-row list in Rust, byte-identical output** |
| `seed.php` | seeds the shared 5000-node tree both sides read |

Environment: `php:8.2-cli` (the prebuilt `qdb/target/release/libquanta_db.so`
is `ext/1.2`, built against API 20220829, so it does not load into `php:8.5-fpm`),
`qdbd` in daemon mode on a tmpfs `/dev/shm`, `rust:1-slim` for the prototype.
Numbers are best-of-N on an unthrottled host; run-to-run spread was under 5%.

Caveats worth carrying forward:

- The workload is **synthetic but structurally faithful** — DirList's
  pre-substitution, the fixpoint loop, real `Qtag` objects, real extension
  reads. It is not the real page, and the real page's qtags are heavier.
- `bench6` runs with ancestors warm and with no site-specific hook
  implementations, so it *understates* the real access cost.
- The Rust prototype implements 7 qtag kinds. Nothing about `Form`, sessions,
  hooks or site modules has been prototyped.

---

## 9. If this is pursued

In order, each independently shippable:

1. **`strtr` in `transformCodeTags`** (§5). Few lines, no new state, byte-identical.
   Count the distinct page-level qtags first to size it.
2. **Profile the 1488 ms page** (§6). Everything after this is better decided
   with that in hand than without it.
3. **Filter `Body::render()`** (§7). Independent of all of the above.
4. **Compile in PHP** (§2): tokenizer, op program, program cache keyed on
   template mtime, interpreter kept as the fallback for dynamic fragments and
   for `attributes=` call sites. Verify by byte-identical page hashes, which is
   the check [qtag-cache-plan.md §6](qtag-cache-plan.md) already established.
5. **Publish programs into the segment** (§4b). Small, low-risk, reuses `image.rs`.
6. **Effective permissions in Rust** (§4c). Prerequisite for the next step, and
   worth something on its own.
7. **Rust execution for data-only fragments** (§4a), with a PHP callback op (§4d).

Steps 1–3 are worth doing whether or not 4–7 ever happen.
