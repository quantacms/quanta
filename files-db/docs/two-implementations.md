# One `FilesDb` API, two implementations

Investigation and plan for removing the "is the extension there?" branch from
Quanta's call sites, by giving the legacy filesystem code a real implementation
of the `FilesDb` API instead of leaving it scattered across the callers.

This is the item `simpler-faster.md` §3.4 named and did not do:

> **Coherent mode is the only mode a caller writes code for.** When
> `db()->coherent()` is true — the normal state — callers should not be
> implementing a second lookup. Fallback belongs *inside* `FilesDb`, not spread
> across its callers.

Everything in that document's §7 plan (steps 1-8) has shipped. This is what was
left.

**Status: implemented.** §1-§8 are the investigation and the plan, kept as they
were written. §9 is what actually happened, including the three places the plan
was wrong and the two things it did not see.

---

## 1. Verdict

**Feasible, and worth doing — but the premise needs one correction.**

The ask was: two classes with the same methods, and the autoloader picks one
based on whether the extension is loaded. The first half is right. The second
half is not quite, for a reason that shows up in four places: **the choice
between the two implementations is not made once per process, it is made per
call.** Even with the extension loaded and a coherent daemon, there are calls
the extension cannot serve and must hand back to the filesystem.

So the shape is not two sibling classes selected at boot. It is:

```
FilesDb           — the filesystem implementation. Total: every method answers.
  └ FilesDbExt    — extends it. Tries the extension; parent::x() on any miss.
```

`Environment::db()` instantiates the subclass when `QuantaDb` is present and the
base otherwise. Call sites see one class, one set of methods, and no `NULL`
"could not answer" state to write a second implementation for. The per-call
fallback that has to exist still exists — but it lives in *one* override each,
not in nineteen call sites.

---

## 2. What is actually there today

`FilesDb.class.php` is 950 lines. It is not a thin wrapper: it already carries
filesystem bodies for `data()`, `object()`, `raw()`, `value()`, `langs()`,
`children()` and `legacyRaw()`. What it does **not** carry is the write side and
the resolver, and that is where the branching leaked out.

### 2.1 The 19 call sites that carry a second implementation

| # | Site | API used | Fallback code living at the call site |
|---|---|---|---|
| 1 | `Environment::scanNodeDirectory` `:422` | `resolvesTo` `children` | `scanDirectory()` + `array_values()` |
| 2 | `Environment::getCandidatePath` `:634` | `exists` | build a `Node`, read `->exists` |
| 3 | `Environment::nodePath` `:804,860` | `coherent` `path` | shard symlink cache, `exec(find)`, duplicate-folder `Message` |
| 4 | `Node::loadJSON` `:154-215` | `load` `coherent` `resolvesTo` | `is_file` + `file_get_contents` + `json_decode`; **plus its own `class_exists`/`method_exists` probe** |
| 5 | `Node::hasChildren` `:429` | `resolvesTo` `children` | `scanDirectory()` + `is_dir` loop |
| 6 | `Node::hasChild` `:465` | `resolvesTo` `children` | `is_dir()` |
| 7 | `Node::delete` `:639` | `delete` | `mkdir` trashbin + rename + `Message` |
| 8 | `Node::getCategories` `:1046` | `links` | `exec(find -L … -samefile)` |
| 9 | `Node::hasTranslation` `:1111` | `resolvesTo` `langs` | `is_file()` |
| 10 | `JSONDataContainer::saveJSON` `:74-97` | `resolvesTo` `put` | `mkdir` + `fopen`/`fwrite` + two `Exception`s |
| 11 | `NodeFactory::unlinkNodes` `:203` | `unlink` | node loads, `is_link`, `unlink`, 3 `Message`s |
| 12 | `NodeFactory::linkNodes` `:281-296` | `link` `unlink` | `symlink()` + `Message`s |
| 13 | `NodeFactory::duplicate` `:800` | `langs` | `glob` + `preg_match` |
| 14 | `UserFactory::getUserFromField` `:94` | `find` | `exec(grep -r -i)` |
| 15 | `FastDirList::quantaDbPath` `:159` | `path` | prefix tree-walk |
| 16 | `Job::safeMove` `:63` | `resolvesTo` `move` | `exec(mv -T)` |
| 17 | `Doctor::checkBrokenLinks` `:207` | `reindex` | — (message suppressed) |
| 18 | `integrity.hook.inc` `:125-261` | `resolvesTo` `langs` `raw` `putRaw` `deleteDoc` | `glob` + `file_exists` + `unlink` + `rename`, three times over |
| 19 | `sitemap.hook.inc` `:31` | `meta` | `file_exists` + `filemtime` |

51 `db()->` calls across 10 files. Nine of the sites open with a `resolvesTo()`
guard whose only purpose is to decide which of two bodies to run.

### 2.2 The internal guards

Inside `FilesDb` itself: `available()` is tested at `:165 :297 :325 :372 :417
:465 :599 :915`, and `coherent()` at `:170 :471`. Those are the ones that turn
into the subclass boundary — each becomes an override.

---

## 3. Why "pick one class at boot" is not enough

Four cases where a loaded, coherent extension still cannot answer. Each is a
real behaviour the current code depends on, not a hypothetical.

**(a) `children()` is not always expressible.** `FilesDb.class.php:579-597`
spends twenty lines deciding whether the caller's `type` × `symlinks` ×
`exclude_dirs` combination maps onto the contract's vocabulary at all.
`DIR_ALL`, `DIR_FILES`, and any `exclude_dirs` other than `'_'` do not — the
index holds nodes, and those combinations also want `tpl.html`, `data.json` and
uploads, which are deliberately unindexed. Those calls must `scandir()` even in
daemon mode.

**(b) `coherent()` can be FALSE while the extension is loaded.** With no daemon
the extension serves from a per-process filesystem walk, so a *miss* proves
nothing. `Environment::nodePath():804` reads exactly this: coherent → trust the
index and skip the symlink shard; not coherent → the shard and `exec(find)` are
still load-bearing.

**(c) The extension throws at runtime.** A daemon that dies mid-request, a
`CORRUPT_JSON`, a stale `.so` missing a method. `Node::loadJSON:210` documents
that the `CORRUPT_JSON` case *must* reach the legacy read, because the two
produce different nodes — the extension throws, the legacy read yields an empty
object and then applies the json fields, which sets the node's status. That
difference is behaviour, and it is pinned by tests.

**(d) Operations outside the contract.** `linkNodes` with a custom symlink name
(a link is always named after its target in the contract), `getCategories`
scoped to a subtree, and `UserFactory`'s case-insensitive match — the index does
equality only, so an empty `find()` still has to reach the `grep`.

A boot-time class swap has no answer for any of these. Inheritance does:
`FilesDbExt::children()` checks expressibility and calls `parent::children()`;
`FilesDbExt::load()` catches and calls `parent::load()`; and so on. **Nine
overrides, each one line of fallback**, replacing nineteen call-site branches.

---

## 4. The design

### 4.1 Files

```
src/modules/environment/classes/Common/FilesDb.class.php      base — filesystem
src/modules/environment/classes/Common/FilesDbExt.class.php   subclass — extension
```

Keeping the base named `FilesDb` means `Environment::db()`'s `@return FilesDb`,
every docblock, and every mental model stay correct. `FilesDbExt` is an
implementation detail nothing outside `db()` names.

### 4.2 Selection — in the factory, not the autoloader

```php
public function db() {
  if ($this->files_db === NULL) {
    if (!class_exists('\Quanta\Common\FilesDb', FALSE)) {
      require_once __DIR__ . '/FilesDb.class.php';
    }
    // No autoload for QuantaDb: an extension registers its classes at startup
    // (see FilesDb::available()).
    if (class_exists('QuantaDb', FALSE)) {
      if (!class_exists('\Quanta\Common\FilesDbExt', FALSE)) {
        require_once __DIR__ . '/FilesDbExt.class.php';
      }
      $this->files_db = new FilesDbExt($this);
    }
    else {
      $this->files_db = new FilesDb($this);
    }
  }
  return $this->files_db;
}
```

**Do not put the decision in the autoloader.** Three concrete reasons:

1. `class_map.dat` is written by `Environment::mapClasses()` and rebuilt **only
   when the file is missing** (`boot.php:20`). A map baked while the extension
   was loaded, then used after `QUANTA_DB_ENABLED=0`, wires the wrong class and
   nothing invalidates it. `Environment::db():49-54` already carries a
   `require_once` workaround for precisely this staleness.
2. `mapClasses():299-303` keys the map by *filename basename*. Two files both
   declaring `Quanta\Common\FilesDb` would need a special case in the map
   builder or in `spl_autoload_register` — a per-request `extension_loaded()`
   branch inside the autoloader, on every class load.
3. The factory is one `if` in one method that is already the single entry point.
   The autoloader route adds a code path to the hottest generic machinery in the
   system to save nothing.

The user-visible outcome is identical: call sites say `$env->db()->foo()` and
never ask which implementation answered.

---

## 5. API changes this forces

### 5.1 `NULL` stops meaning "no answer"

This is the breaking change, and it is the point of the exercise. Today:

- `path()` → `string | FALSE | NULL` — NULL means *run your own lookup*.
- `find()`, `count()`, `put()`, `move()`, `delete()`, `link()`, … → `NULL` means
  the same.

After: the base always answers, so `path()` is `string | FALSE`, `exists()` is
`bool`, `find()` is `array`, `put()` is `bool`. **`NULL` from those methods
becomes impossible**, and every `=== NULL` / `!== NULL` branch at the 19 sites
gets deleted.

Three methods keep `NULL`, honestly, because they have no filesystem analogue
and pretending otherwise would lie to `Doctor`:

| Method | Base returns | Why |
|---|---|---|
| `reindex()` | `NULL` | There is no derived index to rebuild. `Doctor::checkBrokenLinks:208` already reads NULL as "don't print the line". |
| `stats()` | `NULL` | Counters belong to the extension. |
| `version()` | `NULL` | Same. |

`available()` and `coherent()` also stay — the parity suite's `qdb_ext()`
discriminators and `Doctor` need them — and return `FALSE` on the base.

### 5.2 Two narrow methods worth adding

Composing `hasChild()` out of `children()` and `hasTranslation()` out of
`langs()` would be a **performance regression on the filesystem path**, on two
of the hottest calls in the system:

- `Node::hasChild():471` legacy is a single `is_dir()`. Via `children()` it
  becomes a full `scandir()` + `is_dir()` per entry + `in_array()`. On a large
  container that is orders of magnitude worse.
- `Node::hasTranslation():1114` legacy is a single `is_file()`, and its own
  comment records that `NodeFactory::load()` asks it *for every node it loads*.
  Via `langs()` it becomes a `glob()`.

So the API grows two methods that both implementations can serve optimally:

```php
public function child($father, $name);        // bool
public function hasLang($name, $lang);        // bool
```

Base: `is_dir()` / `is_file()`. Subclass: the index probe it already does.

### 5.3 `path()` absorbs `nodePath()`'s extra parameters

`Environment::nodePath($folder, $link = FALSE, $clear_cache = FALSE)` has 24
callers. Two of its three behaviours have to move with the resolver:

- `$link = TRUE` — also accept symlink candidates. `Environment.class.php:800`
  records that these deliberately stay on `exec(find)`; the index will not
  answer them. → `path($name, array('link' => TRUE))`.
- `$clear_cache` — `Node::save():530` and `User::save():286` drop a name from
  the per-request memo after a write. → `forget($name = NULL)`.

---

## 6. Hazards, each with its fix

### 6.1 Infinite recursion between `Environment::nodePath()` and `FilesDb::path()`

**The one that bites first.** Today `FilesDb::path():165` returns `NULL`
immediately when the extension is absent, so it never re-enters Quanta; and
`FilesDb::nodePath():537` (the protected two-state helper) is what calls
`$this->env->nodePath()`. If the base's `path()` is implemented as "the legacy
resolution" while `Environment::nodePath():804,860` still calls `db()->path()`,
the two call each other forever.

**Fix:** make the direction one-way. The legacy resolver body — the static
`$node_paths` / `$missing_nodes` memo, the `Cache::getStoredNodePath()` shard
symlink, `exec(find)`, and the duplicate-folder `Message` — moves *out of*
`Environment::nodePath()` and *into* `FilesDb::path()`. `Environment::nodePath()`
becomes a thin BC wrapper:

```php
public function nodePath($folder, $link = FALSE, $clear_cache = FALSE) {
  if ($clear_cache) { return $this->db()->forget($folder); }
  return $this->db()->path($folder, array('link' => $link));
}
```

The protected `FilesDb::nodePath()` helper then disappears — `path()` *is* the
resolver.

This is the single largest piece of work in the plan (`Environment::nodePath()`
is ~140 lines of carefully-tuned caching with profiling notes attached) and it
should be its own commit with nothing else in it.

### 6.2 Write fallbacks emit user-facing `Message`s

`NodeFactory::linkNodes` / `unlinkNodes`, `Node::delete` and
`JSONDataContainer::saveJSON` do not just write — their legacy branches emit
`Message` objects and throw `Exception`s with specific strings (two of them in
Italian). Moving the write into the base class has to decide where that goes.

**Fix:** the base does the *filesystem operation* and reports success/failure in
the contract's vocabulary; the call site keeps the wording.

- `link()` / `unlink()` already take `if_exists` / `if_not_exists` with an
  `'error'` value — that is an error-signalling contract. The base honours it by
  throwing `QuantaDbException`-shaped failures (see 6.3), and the call site
  catches and formats its `Message` exactly as today.
- `saveJSON()`'s two exceptions are about the *filesystem* (`mkdir` denied,
  `fopen` denied). They move into the base's `put()` unchanged.
- `Node::delete()`'s `Message` is a log line about a successful delete. It stays
  at the call site, which now runs unconditionally.

### 6.3 `QuantaDbException` is an extension class

The base cannot throw it — with no `.so` the class does not exist, and every
`catch (\QuantaDbException $e)` in `FilesDb::call():921` and at call sites would
be a fatal on an undefined class.

**Fix:** declare `Quanta\Common\FilesDbException` in the base file and have the
subclass wrap the extension's exception in it (preserving `getCode()`, which
carries `EXISTS` / `CORRUPT_JSON` / `BAD_ARGS`). Call sites catch the Quanta
class. This also removes the asymmetry `FilesDb::load():386-393` documents,
where `strict` re-throws `\Throwable` in one method and `\QuantaDbException` in
another.

### 6.4 `Node::loadJSON` bypasses `FilesDb` entirely

`Node.class.php:150-155` keeps its own `static $qdb = class_exists('QuantaDb',
FALSE) && method_exists('QuantaDb', 'load')`. That probe exists to defend
against a stale `.so`, and it must be deleted — the whole point is that the call
site stops asking. The `method_exists` guard belongs in
`FilesDbExt::available()`.

The `CORRUPT_JSON` behaviour (3c) must survive: `FilesDbExt::load()` catches,
and `parent::load()` does the `file_get_contents` + `(object) json_decode` that
yields the empty object which then gets its json fields applied. That is the
same code, moved.

### 6.5 `find()` on the base is a real query engine

`UserFactory::getUserFromField:94` uses `find(['father' => …, 'where' => …])`.
A total base implementation means `find()` has to support `father`, `lineage`,
`in`, `where`, `name_prefix`, `order_by`, `limit`, `offset` and three `return`
shapes over the filesystem. That is genuinely new code, and it is the only place
in this plan where the base is not a move of existing code.

**Fix:** scope it. The base implements what the wired call sites actually pass —
today that is `father` + equality `where` + `limit` + `return: names` (one call
site) — over `scanDirectory()` + `data()`. Everything else throws
`FilesDbException(BAD_ARGS)`, which is honest and fails loudly in tests rather
than silently returning the wrong set. Widen it when a call site needs it.

Note that `UserFactory` also cannot lose its `grep` outright: the legacy match
is case-*insensitive* and `where` is equality, so the base's `find()` here is a
strict subset. Either the base's `where` grows a case-insensitive comparison, or
that one call site keeps its fallback with a comment saying why. Recommend the
former — it makes the two implementations agree, which the parity suite wants
anyway.

### 6.6 `children()` array shape

`FilesDb::children():615-620` already documents this: `scanDirectory()`
`unset()`s out of a `scandir()` result, so it returns an array with holes, while
the index returns a list — and `json_encode()` turns a gappy array into an
*object*. Both bodies already `array_values()`. Keep that; it is now internal to
the base, and `Environment::scanNodeDirectory()`'s duplicate `array_values()`
(`:430`) can go.

### 6.7 `Cache` shard-symlink ownership moves

`Cache::getStoredNodePath()` / `storeNodePath()` become internals of
`FilesDb::path()`. `Cache.class.php:69-155` carries comments naming
`Environment::nodePath()` as the writer, and `FileFactory.class.php:28-38`
carries a comment about the cache no longer being written in coherent mode. Both
need updating in the same commit. Both files are already modified on this branch.

### 6.8 The base is slower — that is fine, but say it out loud

Nothing here makes the no-extension path faster. It makes it *the same speed,
written once*. The one place to watch is 5.2: naive composition would make two
hot calls dramatically worse, which is why those two methods get their own
entries in the API.

---

## 7. Sequenced plan

Each step leaves the tree working and the parity suite green. Steps 1-3 are
preparation and change no behaviour.

| # | Step | Touches | Behaviour change |
|---|---|---|---|
| 1 | Add `FilesDbException`; make `strict` re-throw it uniformly. Wrap `QuantaDbException` in `call()` and `load()`. | `FilesDb` + 4 call sites | No |
| 2 | Split the file: `FilesDbExt extends FilesDb`, moving each `available()`-guarded body into an override that ends in `return parent::x()`. Base keeps today's legacy bodies. `Environment::db()` picks. | 2 files | No — same code, same order |
| 3 | Add `child()` and `hasLang()` to both. Convert `Node::hasChild` and `Node::hasTranslation` to them. | 3 files | No |
| 4 | **Move the resolver.** `Environment::nodePath()` body → `FilesDb::path()`; add `path($name, ['link'=>…])` and `forget()`. `nodePath()` becomes a wrapper. Delete the protected `FilesDb::nodePath()`. | `Environment`, `FilesDb`, `Cache`, `FileFactory` | No — but the riskiest commit |
| 5 | Reads: implement `children()`, `langs()`, `meta()`, `links()` totally on the base. Delete the fallback branches at sites 1, 5, 8, 13, 19 and the `resolvesTo()` guards in front of them. | 6 files | `NULL` no longer returned |
| 6 | Writes: `put()`, `putRaw()`, `update()`, `deleteDoc()`, `move()`, `delete()` on the base (filesystem bodies moved from sites 7, 10, 16, 18). | 5 files | `NULL` no longer returned |
| 7 | Links: `link()`, `unlink()`, `relink()` on the base, with the `if_exists`/`if_not_exists` error contract. Sites 11, 12 keep only their `Message` formatting. | 2 files | `NULL` no longer returned |
| 8 | `find()` / `count()` on the base, scoped per 6.5, with case-insensitive `where`. Site 14 loses its `grep`. | 2 files | `NULL` no longer returned |
| 9 | Delete `exists()`'s three-state handling (site 2), `Node::loadJSON`'s inline probe (site 4), `FastDirList::quantaDbPath` (site 15), `quantaDbPathFor()`. Rewrite the class docblock. | 5 files | `NULL` no longer returned |

Steps 5-8 are independent of each other and can land in any order or in
parallel. Step 4 gates nothing but is the one to do while the tree is otherwise
quiet.

---

## 8. Testing

The safety net already exists and is unusually good for this: `files-db/tests/
quanta/` runs every test in three modes — `noext` (no `.so`), `fallback`
(extension, no daemon), `daemon` — and asserts *identical behaviour* in all
three. That is precisely the invariant this refactor must not break, and
`06_wired.php` closes the other side using the extension's operation counters,
so a call site that quietly stopped reaching the extension fails too.

Existing coverage maps onto the sites well: `01` the read surface, `02` Node /
NodeFactory / `saveJSON`, `03` the listing layer including `FastDirList`, `04`
links / moves / `Job::safeMove` / `getCandidatePath`, `05` the integrity hook,
`06` the counters.

**Gaps to fill before step 5**, all cheap:

- `UserFactory::getUserFromField` — nothing covers site 14, which is the one
  getting new code (6.5). Needs a case-difference case specifically.
- `NodeFactory::duplicate` — site 13's `langs()`/`glob` pair, including the
  `pt-br` case its own comment says the old `\w+` regex got wrong.
- `sitemap.hook.inc` — site 19's `meta()['mtime']` vs `filemtime()`.
- `Doctor::checkBrokenLinks` — site 17, to pin that `reindex()` staying `NULL`
  on the base keeps the message suppressed.
- `Environment::nodePath()` directly — the `$link = TRUE` and `$clear_cache`
  paths, before step 4 moves them.

Add `QDB_MODES=noext` as a fast pre-commit loop during steps 5-8: the base is
what is changing, and `noext` is the mode that exercises it.

---

## 9. What actually happened

Implemented. The parity suite (`files-db/tests/quanta/`) runs green in all three
modes — **774 assertions, 0 failures** across `noext`, `fallback` and `daemon` —
and the extension's own conformance suite (`files-db/tests/php/`, 73 assertions)
is unchanged.

### 9.1 What shipped

```
src/modules/environment/classes/Common/FilesDb.class.php        the filesystem
src/modules/environment/classes/Common/FilesDbExt.class.php     the extension
src/modules/environment/classes/Common/FilesDbException.class.php
```

`Environment::db()` picks. All 19 call sites lost their second body, and
`Environment::nodePath()` is now nine lines wrapping `FilesDb::path()`.

### 9.2 Where the plan was wrong

- **§4.1 said "keep the base named `FilesDb`, the subclass is an implementation
  detail". That held** — but `resolvesTo()` did not become the guard at nine
  call sites, `'at'` did. Passing the directory *into* the call, rather than
  asking a question in front of it, is strictly better: on the extension it is
  the same single probe the guard was, and on the filesystem it removes the
  resolution entirely (the caller already holds the answer). The plan's §5
  never considered this and it is the single biggest simplification in the diff.
- **§5.2 was right, and did not go far enough.** `child()` and `hasLang()` were
  added as planned. `path()` also grew `'search' => FALSE` — "the cheap layers
  only, do not `exec(find)`" — because `FastDirList` exists precisely to dodge
  that search, and a total `path()` would have quietly reintroduced it.
- **§6.5 said scope `find()` and throw `BAD_ARGS` for the rest.** Implemented
  fully instead — `father`, `in`, `lineage`, `name_prefix`, `where` with dot
  paths, `order_by` (name / mtime / `json:`), `order`, `limit`, `offset`,
  `return` names/data/meta. Only an *unbounded* find throws. The reason to go
  further: `where` equality is subtler than it looks, and the extension already
  has a 25-case conformance corpus for it. Those cases now run through
  `$env->db()` in `01_shim_reads.php`, so both implementations are held to
  `json_eq()`'s rules — `'10' != 10`, `1 != true`, a missing key never matches
  `null`, `tags.x` and `customer.0` find nothing. A hand-rolled `==` got four of
  those wrong on the first pass.
- **§9.1 (the open decision on `UserFactory`) resolved the other way.** The
  `grep` stays, and that is now honest rather than a fallback: `where` is
  equality, the grep is case-insensitive, so an empty `find()` is a definitive
  "no exact match" and the grep is a genuine widening of the query. No Rust
  change needed.

### 9.3 Two things the plan did not see

- **The `__MISSING__` marker outlives a create.** A create resolves the name
  first (to discover nothing is there), which writes `__MISSING__` into the
  `tmp/cache` shard — and then the node exists. The old code got away with it
  because every caller paired `nodePath(..., clear)` with a `storeNodePath()` of
  its own. With `put()` owning creation, `forget()` had to drop the on-disk
  marker too, and `reserve()` now writes the new path positively rather than
  merely invalidating. Found by the smoke test, not by reading.
- **`serves()` nearly cost a probe per document read.** The first cut asked the
  index "do you hold this name?" before every read. With no `'at'` to check that
  is pure waste — the read that follows asks the same question and answers it —
  and it would have put back exactly the tax `simpler-faster.md` §4.1 removed.
  `serves()` now short-circuits to TRUE when there is nothing to check;
  `writable()` stays strict, because a write cannot recover from the
  extension's answer to a node it does not hold, and writes are not hot.
  Verified against the extension's counters: `index_serves`, `shm_hits`,
  `img_serves` and `children_ops` are identical to the baseline over a 20-node
  workload exercising every rewired call site.

### 9.4 Behaviour changes, all deliberate

| | Before | After |
|---|---|---|
| `link(if_exists => 'ignore')` on an existing link | TRUE with the extension, FALSE without | TRUE either way |
| `unlink()` with no such link, `if_not_exists => 'error'` | extension threw, filesystem did not | both raise `IO` (the extension's code) |
| `link()` into an unresolvable container | FALSE without the extension | both raise `BAD_ARGS` |
| a duplicate node name on create | filesystem created it silently; the extension refused, and the refusal was swallowed | both refuse, and `saveJSON()` passes `if_exists => 'ignore'` to keep the old result — in one visible line |
| `hasTranslation('')` | `is_file('data_.json')`, always FALSE | FALSE, stated as a rule |
| `meta()` on the filesystem | NULL | `path`/`father`/`mtime`/`langs`; `generation` and `containers` stay extension-only |

`meta()` is the one asymmetry left. It is deliberate: `containers` costs an
`exec(find)` per node on the filesystem, and the caller that asks for `meta()`
does it once per page (`sitemap.hook.inc`). Ask `links()` for containers — both
implement it.

### 9.5 One addition outside the plan

`DataContainer::getEnv()`. `integrity_check_node($node, $env = NULL)` keeps an
optional `$env` for old callers, and the repair now needs one — the node was
carrying its Environment the whole time.

---

## 10. Still open

1. **Should a duplicate node name be a user-visible error?** Both
   implementations now refuse it; `JSONDataContainer::saveJSON()` passes
   `if_exists => 'ignore'` to keep Quanta's historical result. Dropping that one
   option turns the refusal into an `EXISTS` the caller must handle. Audit first
   (`find(['name_prefix' => ''])`, or `Doctor`) — an existing tree can hold
   duplicates that were always silent.
2. **`Node::delete()`'s trashbin.** `FilesDb::delete()` moves to
   `env->dir['trashbin'] . '/' . time()`; the extension's to
   `quanta_db.trashbin_dir`. They are pointed at the same place in the image
   (`docker/docker-entrypoint.sh`). Confirm that holds in every deployment, or
   the two put deleted nodes in different directories.
3. **`FilesDb::find()` always walks.** No index, by design — `_users` is the
   only caller and its fan-out is bounded. Revisit if a second one appears, and
   note that a `lineage` find walks the whole subtree.
4. **`update()` is not atomic on the filesystem**, and `relink()` is an unlink
   followed by a link. Those are the two guarantees only the extension can make.
   Both are documented at the methods; neither has a Quanta call site that
   depends on the guarantee today.
5. **`available()` / `coherent()` stay public** for `Doctor`, the parity suite's
   discriminators and deployment reporting. Watch for a new call site branching
   on them — that is the failure mode this whole change exists to remove, and
   nothing in the code stops someone re-introducing it.
