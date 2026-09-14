# Code review findings — logic bugs & no-op optimizations

Read-only review of `src/` (PHP core + module classes). Nothing was fixed; this is a
list of things that look like plain mistakes, plus changes that would cost less
without changing behaviour.

Each item is `file:line`. Grouped by how much damage it can do.

---

## 1. Broken logic — these produce wrong results today

### 1.1 `duplicate()` computes the sub-node father from itself
`src/modules/node/classes/Common/NodeFactory.class.php:783`

```php
$new_subnode_father = str_replace($source_node->father, $new_node_name, $new_node_name);
```

The haystack is `$new_node_name` and the replacement is `$new_node_name` — the
expression can only ever return `$new_node_name`, whatever the search string is.
The haystack was presumably meant to be `$subnode->father` (or `$subnode->path`).

On top of that, `Node::$father` holds a **Node object**, not a string
(`Node.class.php:38`, assigned in the constructor via `NodeFactory::load`), so
passing it as `str_replace`'s first argument is a `TypeError` in PHP 8 whenever the
source node has a built father.

### 1.2 `createNode()` shares the JSON object with the source node
`src/modules/node/classes/Common/NodeFactory.class.php:819`

```php
$new_node->json = $source_node->json;
```

`$json` is a `stdClass`, so this is an aliasing assignment, not a copy. Every
`setAttributeJSON()` / `removeAttributeJSON()` applied to the *new* node afterwards
(the `$overrides` and `$exclude` loops right below) also mutates the **source**
node's in-memory document. If the source node is saved later in the same request —
`duplicate()` keeps using `$source_node` for the sub-node recursion — the excluded
fields are gone from the original too. Needs `clone` (deep, for nested objects).

### 1.3 List `type` attribute silently dropped in the deep-scan branch
`src/modules/list/classes/Common/ListObject.class.php:236` and
`src/modules/sitemap/hooks/sitemap.hook.inc:22`

```php
$list_nodes = $this->env->scanDirectoryDeep($this->path, '', array(
  'exclude_tree' => $this->exclude_tree,
  'exclude_dirs' => Environment::DIR_INACTIVE,
  'symlinks'     => $symlinks,
  $this->scantype,          // <-- positional: lands at key 0, not 'type'
  'level'        => $this->getData('level')
));
```

The sibling branch fifteen lines down gets it right (`'type' => $this->scantype`,
line 249). As written, `$attributes['type']` is never set, `scanDirectory()` falls
back to its `DIR_ALL` default, and a `level=leaf`/`level=tree` list ignores its
scantype entirely — files come back where only directories were asked for.

Same typo in the sitemap hook.

Related: `scanDirectoryDeep()` reads `$attributes['level']` unguarded
(`Environment.class.php:480, 493`). The parameter default supplies `'level'`, but a
caller that passes an attributes array without it gets an undefined-index warning.
Both current callers happen to pass it.

### 1.4 `Qtag::load()` blanks already-rendered HTML
`src/modules/qtags/classes/Qtags/Qtag.class.php:143`

```php
$this->html = '';                                  // unconditional
if ($this->getAccess() && !$this->rendered) { ...render, set $this->html... }
```

If `load()` runs a second time on an object that already rendered (`$rendered ===
TRUE`), the HTML is wiped to `''` and never restored. `__toString()` (line 328)
calls `load()` unconditionally, so printing a Qtag that `preload()` already rendered
yields an empty string. The reset belongs inside the `if`, or should be guarded on
`!$this->rendered`.

### 1.5 `Qtag::highlight()` filters attribute *values* against attribute *names*
`src/modules/qtags/classes/Qtags/Qtag.class.php:205`

```php
foreach ($this->attributes as $attribute_name => $attribute_value) {
  if (($attribute_value != "showtag") && ($attribute_value != "highlight")) {
```

`showtag` and `highlight` are attribute **names** whose value is boolean `TRUE`
(`QtagFactory::parseQTag()` sets `TRUE` for valueless attributes). The comparison
therefore never fires, and `|showtag` / `|highlight` are echoed back inside the
highlighted markup. Should test `$attribute_name`.

### 1.6 `Api::valid_email()` rejects every address starting with a capital
`src/modules/api/classes/Common/Api.class.php:58`

```php
return (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !ctype_upper($email[0]));
```

`Foo@bar.com` is a perfectly valid address and is rejected. If a
lowercase-normalisation rule was intended, it should be `strtolower($email) ===
$email` (and even that is wrong for the local part per RFC). Also `$email[0]` warns
on an empty string.

### 1.7 `Api::valid_password()` — unescaped `-` turns the class into a range
`src/modules/api/classes/Common/Api.class.php:96`

```php
'/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()-_+=])[A-Za-z\d!@#$%^&*()-_+=]{8,}$/'
```

Inside a character class `)-_` is the range `0x29`–`0x5F`, i.e. `)*+,-./`, **all
digits**, `:;<=>?@`, **all uppercase letters**, and `[\]^_`. So the "must contain a
special character" lookahead is satisfied by any digit or capital, and the allowed
alphabet quietly includes characters the rule meant to reject. The `-` needs
escaping or moving to the end of the class.

### 1.8 `Api::normalizePath()` never collapses a leading `--`
`src/modules/api/classes/Common/Api.class.php:201`

```php
while (strpos($s, '--') > 0) { $s = str_replace('--', '-', $s); }
```

`strpos` returns `0` when the match is at offset 0, which is falsy-ish here and
`0 > 0` is false — so a string that *starts* with `--` skips the whole loop and its
internal doubles are never collapsed: `--a--b` → (trim) → `a--b`. Needs
`!== FALSE`.

### 1.9 `Environment::getCandidatePath()` grows the path on every collision
`src/modules/environment/classes/Common/Environment.class.php:660`

```php
$candidate_path = $candidate_path . '-' . time() . '-' . rand(1000,9999);
```

The suffix is appended to the *previous candidate*, not to the base. Two collisions
in a row produce `title-1755-4821-1755-9930`. `$i = 0;` above the loop is declared
and never used, which suggests the original intent was `$base . '-' . ++$i`.

### 1.10 `Doctor::checkBrokenLinks()` — `isset` on the wrong index
`src/modules/doctor/classes/Common/Doctor.class.php:228`

```php
$link_father = isset($link_split[0]) ? $link_split[1] : NULL;
```

Guards index `0`, reads index `1`. For a symlink at the filesystem root the guard
passes and the read warns/returns NULL, and `linkNodes()` is then called with a NULL
container.

### 1.11 `Image::loadAttributes()` stores the whole attribute array as the value
`src/modules/image/classes/Common/Image.class.php:57`

```php
default:
  $this->setData($attname, $attributes);   // should be $attribute
```

Every unrecognised image attribute gets the complete attributes array as its value.

### 1.12 `Image` size-attribute regex is a character class, not an alternation
`src/modules/image/classes/Common/Image.class.php:33`

```php
preg_match_all('/[0-9|auto]x[0-9|auto]/', $attname, $matches)
```

`[0-9|auto]` matches *one* character out of `0-9 | a u t o`. It works by accident for
`100x200` (the `0x2` substring matches) but it also matches unrelated attribute names
containing e.g. `ox`+`a`, which are then `explode('x')`-ed into width/height. The
intent is `/^(\d+|auto)x(\d+|auto)$/`.

### 1.13 `Page::buildHTML()` — two branches are unreachable
`src/modules/page/classes/Common/Page.class.php:91-100`

`$index_file` defaults to `'index.html'` and is only ever set to another filename,
so `!empty($this->getIndexFile())` is effectively always true. That makes the
`getData('content')` branch (documented as "Shadow node edit") and the
"Quanta seems not installed" branch dead code. When `index.html` is genuinely
missing, `file_get_contents()` returns `FALSE` with a warning instead of the install
message. The `is_file()` test needs to move into the second branch's condition.

### 1.14 `DirList::sortBy()` is not a valid comparator
`src/modules/list/classes/Common/DirList.class.php:379-408`

* It only ever returns `1` or `-1`; equal elements report "greater", which makes the
  comparison non-transitive and the resulting order effectively arbitrary.
* In the `title` case, `$check = -1` for the "one title is NULL" path — `-1` is
  truthy, so `return ($check) ? 1 : -1` returns **1**, the opposite of what the
  author meant.

### 1.15 `FileObject::deleteFile()` deletes the file even when access is denied
`src/modules/file/classes/Common/FileObject.class.php:429-432`

```php
if (!NodeAccess::check($node->env, Node::NODE_ACTION_EDIT, array('node' => $node))) {
  new Message($node->env, t('Error: you have no permissions...'), Message::MESSAGE_ERROR);
}
unlink($node->path . '/' . $file);
```

Missing `return` after the message: the `unlink()` runs unconditionally. `$file` is
also not sanitised against `../`.

### 1.16 `FileObject::getFileSize()` stats a relative filename
`src/modules/file/classes/Common/FileObject.class.php:99-102`

```php
$this->size = filesize($this->path);
```

For non-external files `$this->path` is just the filename; the class's own
`getRealPath()` (line 150) is what every other method uses. So `getFileSize()`
returns `FALSE` + a warning for ordinary node files — which is what
`FileList::sortBy()` (`FileList.class.php:108`) sorts on and what
`FileAttribute.qtag.php:33` prints.

### 1.17 `Api::valid_url()` works only by operator-precedence accident
`src/modules/api/classes/Common/Api.class.php:132`

```php
return (!filter_var($url, FILTER_VALIDATE_URL) === FALSE);
```

Parses as `(!filter_var(...)) === FALSE`. It happens to give the right answer for
every input, but it clearly means `!(filter_var(...) === FALSE)` and reads as a bug.

### 1.18 `Localization::switchLanguage()` reads `$_GET` it never checked
`src/modules/localization/classes/Common/Localization.class.php:147-149`

```php
if ($update_language && !empty($update_language)) {
  $lang = $_GET['update_language'];
}
```

`$update_language` can be true because the **caller** passed `TRUE`, while
`$_GET['update_language']` is absent — undefined-index warning, `$lang` becomes
NULL. The condition should read the local variable. (`$x && !empty($x)` is also
just `!empty($x)`.)

`translatableText()` at line 196 has the same shape: `$lang = $_SESSION['language'];`
with no `isset` — should go through `self::getLanguage($env)`.

### 1.19 `duplicate()` file filter doesn't match hyphenated language codes
`src/modules/node/classes/Common/NodeFactory.class.php:766`

```php
if (!preg_match('/^data(_[a-zA-Z]+)?\.json$/', $file_name)) { copy(...); }
```

`data_pt-br.json` doesn't match, so it is copied as if it were an attachment — the
exact case the comment eleven lines above says was fixed for `langs()`. The pattern
needs `[a-zA-Z-]+`. The same loop also `copy()`s directories returned by
`glob('*')`, which fails with a warning.

### 1.20 `UserFactory::verifyToken()` catches a class that doesn't exist
`src/modules/user/classes/Common/UserFactory.class.php:349`

```php
} catch (Exception $e) {
```

Inside `namespace Quanta\Common`, this resolves to `\Quanta\Common\Exception`.
Firebase JWT throws `\UnexpectedValueException` / `\Firebase\JWT\*Exception`, so an
expired or malformed bearer token escapes uncaught and 500s instead of falling back
to the anonymous user. Needs `\Exception` (or `\Throwable`).

### 1.21 Undefined constant `MESSAGE_ERROR` — fatal in PHP 8
`src/modules/environment/classes/Common/DataContainer.class.php:58, 72`
`src/modules/list/classes/Common/ListObject.class.php:455`

The constant is `Message::MESSAGE_ERROR`; there is no global/namespaced
`MESSAGE_ERROR` anywhere in the tree. In PHP 8 an undefined constant is an `Error`,
so all three of these error paths crash instead of reporting.

### 1.22 `UserFactory::requestAction()` builds the default title from stale values
`src/modules/user/classes/Common/UserFactory.class.php:158`

```php
$user->setTitle($user->getFirstName() . ' ' . $user->getLastName());
foreach ($form_items as $key => $value) { ... setFirstName/setLastName ... }
```

The title is composed *before* the new first/last names are applied, so an edit that
changes the name leaves the old title. The comment says "if it's not set", but the
call is unconditional.

### 1.23 `UserFactory::buildUser()` fires `user_presave` without the user
`src/modules/user/classes/Common/UserFactory.class.php:70`

```php
$env->hook('user_presave', $vars);   // $vars is the raw input array
```

Everywhere else (`requestAction`, `User::save`) the hook gets
`array('user' => $user)`. Implementations reading `$vars['user']` get nothing here.

### 1.24 `User::save()` always returns TRUE
`src/modules/user/classes/Common/User.class.php:270-293`

Documented as "returns true if the save action was completed without errors", but
there is no failure path. `UserFactory::requestAction()` line 201-206 branches on it
and `die("ERROR")` in the else — dead code guarding a promise the method doesn't
keep.

### 1.25 `FilesDb::path()` memo ignores the `link` option
`src/modules/environment/classes/Common/FilesDb.class.php:236-241` +
`FilesDb.class.php:407`

`path()` returns a memo hit before it ever looks at `$opts['link']`, and
`resolve()` memoises the result of a `link` search through the same
`remember()`. So the two lookup modes share one cache:

* a plain lookup that ran first hands its real directory to a later `link` caller,
  which then `readlink()`s a real directory and gets `FALSE`
  (`Environment::linkToNode()` at `Environment.class.php:729` does exactly that);
* a `link` lookup that ran first poisons the memo for every plain caller in the rest
  of the request.

Either key the memo on the mode, or skip the memo for `link` searches.

### 1.26 `FilesDb::search()` interpolates the node name into a shell command
`src/modules/environment/classes/Common/FilesDb.class.php:436-440`

```php
$findcmd = 'find ' . $this->env->dir['docroot'] . '/ -type d -name "' . $name . '"' ...
exec($findcmd, $results);
```

`$name` reaches here from `nameOf()`, which strips only `/ ? #` — quotes, `$`,
backticks and `;` all survive, and the resolver is fed request-URI segments. Every
other `exec()` in this class uses `escapeshellarg()`; this one doesn't.

Same pattern, lower reach: `Api::minify()` (`Api.class.php:315`),
`Cache::clear()` (`Cache.class.php:219`), `cache_doctor_clear_cache()`
(`cache.hook.inc:51`) and `Doctor::checkBrokenLinks()`'s `find` (line 214).
`sync.hook.inc:40` escapes every argument except `$override_recent`.

### 1.27 `FileObject::checkUploads()` — path traversal + only one file handled
`src/modules/file/classes/Common/FileObject.class.php:354`

```php
$upload_dir = ($env->dir['tmp_files'] . '/' . $_REQUEST['tmp_upload_dir']);
```

Unvalidated request value used as a directory component, then `mkdir(..., TRUE)`.
Separately, the loop `echo`s and `exit`s after the **first** successfully moved
file, so a multi-file POST silently drops everything after `$_FILES[0]`.

### 1.28 `QtagFactory::transformCodeTags()` has no iteration bound
`src/modules/qtags/classes/Common/QtagFactory.class.php:112-137`

`$transformed` is incremented at the top of the loop and never read — it is very
plainly a leftover max-iteration counter. As it stands, a Qtag that renders to
markup containing itself spins forever inside a request.

### 1.29 `NodeAccess` caches by node+action only
`src/modules/access/classes/Common/NodeAccess.class.php:38-45, 135-151`

Both the static `$access_checked` map and `cacheTag()` key on node name and action.
`Access::__construct()` (`Access.class.php:53`) accepts an explicit
`$vars['user']`, so an access check for user A can be answered from user B's cached
verdict. No current caller passes `'user'` to `NodeAccess::check()`, so this is
latent — but it is one call site away from being an authorisation bug, and it should
either include the actor in the key or refuse a non-current actor.

Also in `checkAction()`: `$permissions = $this->node->getPermissions();` (line 73)
runs *before* the `!is_object($this->node)` guard on line 76 — the guard can never
fire for a non-object, because line 73 already fataled.

### 1.30 `NodeFactory::load()` static cache ignores the language
`src/modules/node/classes/Common/NodeFactory.class.php:30, 35, 62`

```php
static $loaded_nodes;
if (!$force_reload && !empty($loaded_nodes[$node_name])) { return $loaded_nodes[$node_name]; }
...
$loaded_nodes[$node_name] = $node;
```

The request-level `Cache` path right below correctly keys on
`cacheTag($node_name, $language)`. This one doesn't, so a `$force_reload = FALSE`
caller can get the node in the wrong language. The default is `TRUE`, which is why
it hasn't bitten.

### 1.31 `loadFromRealPath()` and `fastLoadFromRealPath()` disagree on the name
`src/modules/node/classes/Common/NodeFactory.class.php:120-121` vs `147`

```php
$exp = explode('/', $path); $node_name = $exp[count($exp) - 2];   // loadFromRealPath
$node_name = basename($path);                                     // fastLoadFromRealPath
```

The first only works when `$path` has a trailing slash; without one it returns the
**father's** name. Two functions with the same contract should extract the name the
same way.

### 1.32 `ListObject::clear()` prevents regeneration
`src/modules/list/classes/Common/ListObject.class.php:416-419`

```php
public function clear() {
  $this->generated = TRUE;      // ← locks generate() out
  $this->rendered_items = array();
}
```

`generate()` (line 376) returns early when `$generated` is truthy. So "clear the
list" also means "this list can never render again". `FALSE` looks like the intent.

### 1.33 `Job::run()` — repeated blocks and `time()`-collision node names
`src/modules/jobs/classes/Common/Job.class.php:117-175`

The "log the failure, load `_jobs_unknown`, `safeMove()` into it, warn on failure"
block is duplicated verbatim at lines 127-136 and 163-172, and again in a near-copy
for `_jobs_done` at 195-205. Worth one private helper.

Log node names are `$this->name . '-log-' . time()` (lines 124, 160, 186, 215) —
two log entries inside the same second collide on the node name, which the database
treats as a tree-wide duplicate.

`safeMove()` is static but called as `$this->safeMove(...)`.

### 1.34 Control keys leak into saved JSON
`src/modules/jobs/hooks/jobs.hook.inc:31`

`NodeFactory::buildNode($env, $node_name, $node_data['father'], $node_data)` passes
the same array as both the father source and the field list, so `buildNode()`'s
`default:` case writes `json->father` and `json->skip_normalize` into the node
document.

### 1.35 `FilesDbExt` — small contract inconsistencies

* `FilesDb::object()` (`FilesDb.class.php:576`) returns `(object) json_decode($raw)`.
  For a document whose whole body is `null` it returns an empty `stdClass` where
  `data()` returns `NULL`; for a top-level JSON *array* it produces an object with
  numeric properties. The extension's `getObject()` almost certainly doesn't do
  either.
* `FilesDb::move()` (line 1462) carries the comment
  `// -T so the destination is an exact name, not a parent to nest under.`
  directly above a plain `exec('rm -rf ...')`. The comment belongs to the `mv -T` in
  `delete()` and was copy-pasted.
* `FilesDb::order()` (line 1152) reverses the whole result for `desc`, including the
  `strcmp` tiebreak that `sortByKeys()` deliberately added for stability — equal-key
  names come back in reverse alphabetical order.
* `FilesDb::write()` (line 1315): the `if ($path === FALSE) return FALSE;` only
  covers the `reserve()` branch; the `else` branch already returned. Harmless, but it
  reads as if it guards both.
* `FilesDb::delete()` (line 1513) uses `mkdir(..., 0777, TRUE)` where every other
  mkdir in the class uses `0755`.

---

## 2. Dead code / no-ops

| Where | What |
|---|---|
| `list/…/ListObject.class.php:226-228` | `if (!empty($this->getData('list_filter'))) { }` — empty body |
| `node/…/NodeFactory.class.php:437-439` | `} else { }` — empty else in `requestAction()`'s form-data loop |
| `node/…/NodeFactory.class.php:47` | `$vars = array('node' => &$node);` in the cache-hit branch — never used (no hook is fired there) |
| `localization/…/Localization.class.php:90-91` | `$vars = array();` immediately overwritten by `$vars = array('fallback_language' => NULL);` |
| `environment/…/Environment.class.php:652` | `$i = 0;` in `getCandidatePath()` — never read |
| `node/…/Node.class.php:230` + `user/…/User.class.php:381` | `updateJSON(array $ignore = array())` — `$ignore` never used in either implementation, and `Node::save()` passes a real list to `saveJSON()` instead |
| `api/…/Api.class.php:181` | `strip_qtags($string, $keep_qtags = array())` — `$keep_qtags` never used; the function also duplicates `QtagFactory::stripQTags()` |
| `localization/…/Localization.class.php:73` | `'value_as_key' => TRUE` passed to `scanDirectory()`, which has no such attribute |
| `cache/…/Cache.class.php:214` + `environment/…/Environment.class.php:729` | `Cache::clear()` and `Environment::linkToNode()` have no callers |
| `doctor/…/Doctor.class.php:277` | `checkExistingIndex()` — empty method body |
| `node/…/Node.class.php:600-602` | `deleteHard()` — empty body with a TODO |
| `image/…/Image.class.php:69-73` | `if ($realpath === false)` inside a block already gated on `is_file($this->getRealPath())`; the `else` is a leftover `error_log("Path: ...")` that fires for every image without explicit dimensions |
| `environment/…/Environment.class.php:625-627` | `if (isset($action_value))` immediately after `$action_value = (...)` |
| `page/…/Page.class.php:10` | `public $includes;` — never read or written |
| `environment/…/Environment.class.php:19` | `public $host = array();` — always assigned a string |
| `api/…/Api.class.php:160, 199` | stray `;;` |

---

## 3. Same result, less work

These change no behaviour.

### 3.1 `scanDirectory()` keeps stat'ing entries it already removed
`src/modules/environment/classes/Common/Environment.class.php:379-402`

Each filter `unset($dirs[$k])` and then falls through to the next filter, which
calls `is_link()` / `is_dir()` / `is_file()` on the path it just discarded. A
`continue` after each `unset` removes 1–2 syscalls per excluded entry on a hot path
that the rest of this subsystem is heavily tuned around.

### 3.2 Double `readlink`/`is_link` on the shard symlink
`src/modules/cache/classes/Common/Cache.class.php:172` →
`src/modules/environment/classes/Common/FilesDb.class.php:317-321`

`getStoredNodePath()` does an `is_link()` and returns the path (or FALSE);
`resolve()` immediately `@readlink()`s it. `readlink()` alone already returns FALSE
for a non-link, so the `is_link()` is a redundant `lstat` on the resolver's hottest
line. (`resolve()` also passes the FALSE straight into `readlink()`, which works but
warns internally.)

Same in `FastDirList::fastResolvePath():98` — and there it passes `$build = TRUE`,
so a *read* creates up to three shard directories, which is exactly what
`FilesDb::resolve()`'s `build = FALSE` comment (line 305-311) says not to do.

### 3.3 `Node::load()` decodes `$_REQUEST['json']` twice
`src/modules/node/classes/Common/Node.class.php:307, 314`

Two consecutive `if (!empty($_REQUEST['json']) && ($json = json_decode(...)))`
conditions. One decode, hoisted above both, does the same job.

### 3.4 `JSONDataContainer::saveJSON()` round-trips through JSON to deep-copy
`src/modules/environment/classes/Common/JSONDataContainer.class.php:74`

```php
$data = (array) json_decode(json_encode($this->json), TRUE);
```

The `(array)` cast is a no-op (`assoc = TRUE` already returns an array), and the
encode+decode pair is an expensive way to convert nested `stdClass` to nested arrays
— on the write path of every node save, for documents that can be hundreds of KB.

### 3.5 `cacheTag()` memoises the cheap half
`src/modules/qtags/classes/Qtags/Qtag.class.php:338-355` and
`src/modules/access/classes/Common/NodeAccess.class.php:135-151`

```php
$combinedString = json_encode($tag) . '_' . json_encode($attributes) . '_' . json_encode($target);
if (!isset($hashed[$combinedString])) { $hashed[$combinedString] = hash('crc32', $combinedString); }
```

The three `json_encode()` calls — the expensive part — happen on *every* call; the
memo only skips the `crc32`, which is far cheaper than the lookup that guards it.
The memo also retains every full key string for the life of the process, so it costs
more memory than not hashing at all.

Separately, `crc32` is a 32-bit space being used as a cache key for rendered HTML
and for **access verdicts** — a collision serves one node's access answer for
another. If the hash is kept, use at least `xxh64`/`md5`.

### 3.6 `find()` re-resolves every candidate
`src/modules/environment/classes/Common/FilesDb.class.php:990, 1005, 1011`

The `where`, `order_by json:` and `return => data` loops all call
`$this->data($name, $lang)` with no `'at'`, so each candidate pays a full
`path()` resolution — up to an `exec(find)` per row on the filesystem
implementation. `candidates()` came from a directory listing and knows the parent
path; threading it through as `'at'` removes the resolution entirely.

### 3.7 Micro
* `Node::hasParent()` (`Node.class.php:346`) sets a flag and keeps iterating the
  whole lineage instead of returning early.
* `Node::getDate()` (`Node.class.php:681`) calls `date_default_timezone_set('UTC')`
  on every invocation; the same call is already at the top of the file (line 3).
* `QtagFactory::parseQTag()` (`QtagFactory.class.php:200`) rebuilds a split value in
  a loop with `count()` in the condition; `implode('=', array_slice($split, 1))`
  does it in one call.
* `ListObject::countItems()` (line 429) and `render()` (line 155) both re-set
  `$this->generated = TRUE` right after `generate()` already did.
* `Message::__construct()` (`Message.class.php:63`) fetches
  `$env->getData('doctor')` unconditionally and uses it only in the
  `Doctor::isCuring()` branch.
* `FastDirList::findInDirectory()` (`FastDirList.class.php:165`):
  `is_dir($candidate) || (is_link($candidate) && is_dir(readlink($candidate)))` —
  `is_dir()` already follows symlinks, so the second clause is unreachable for
  absolute targets and wrong for relative ones.
* `FastDirList::__construct()` and `loadAttributes()` are near-verbatim copies of
  `ListObject`'s (which are `private`, hence the duplication). Making the parent's
  `protected` and overriding only the node-resolution step would drop ~50 lines.
* `Localization::translatableText()` (line 218-221) repeats
  `$node->title != NULL` on both sides of an `||`; factoring it out skips a
  `hasTranslation()` probe when the title is empty.

---

## 4. Comments that no longer describe the code

* `Localization::getLanguageNegotiation()` (line 54) and `getEnabledLanguages()`
  (line 71) both open with `// If the fallback language has already been defined...`
  — copy-pasted from `getFallbackLanguage()`.
* `FilesDb::move()` (line 1462) — `-T` comment above an `rm -rf`, see §1.35.
* `Api::explode_comma_outside_tags()` (line 483-488) documents four negative
  lookaheads; the regex on line 488 has two.
* `UserFactory::getUserFromField()` is annotated `@return User` but both return
  paths hand back a **name string**.
* `Node::getCategories()` is annotated `@param Node $node` but passes the argument
  to `NodeFactory::load()`, which wants a name.
* `Node::getTeaser()` (line 509): `preg_replace('/\[[^>]*\]/', ...)` — the class
  excludes `>` while the delimiters are `[` and `]`, so it strips from the first `[`
  to the last `]` in a run, swallowing text between two separate qtags. Almost
  certainly meant `[^\]]*`.
* `Localization::getFallbackLanguage()` (line 97) picks the fallback with
  `array_pop()` — the *last* directory entry alphabetically. Nothing in the code or
  comments explains why last rather than first or an explicit default.
* `Node::updateJSON()` (line 240): `$this->json->weight = empty($this->getWeight())
  ? time() : $this->getWeight();` — a default weight of the current unix timestamp
  looks like a copy of the `timestamp` line above it. If deliberate, it deserves a
  comment.
