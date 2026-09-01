<?php

namespace Quanta\Common;

require_once __DIR__ . '/FilesDbException.class.php';

/**
 * The file-based node database.
 *
 * Quanta stores every node as a directory holding data*.json. That is the
 * database and it stays the database. This class is the complete filesystem
 * implementation of it — exec find, scandir, file_get_contents, glob, mkdir,
 * symlink — and it answers every method on its own.
 *
 * FilesDbExt extends it and serves the same methods from the `quanta_db`
 * native extension (files-db/docs/api-contract.md), which reads from a
 * shared-memory projection of the tree and makes writes locked and atomic.
 * Where the extension cannot answer — an inexpressible query, an incoherent
 * index, a thrown error — the override calls parent:: and lands back here.
 * Environment::db() picks the subclass when the extension is loaded.
 *
 * ## The rule this class exists to enforce
 *
 * **Every method answers.** A caller never writes a second implementation and
 * never branches on which one replied. That is the whole point of the split:
 * before it, "the extension could not answer" was a routine return value
 * (NULL), and nineteen call sites carried a complete legacy body to handle it —
 * Environment::nodePath, Node::loadJSON/hasChild/hasChildren/hasTranslation/
 * delete/getCategories, JSONDataContainer::saveJSON, NodeFactory::linkNodes/
 * unlinkNodes/duplicate, UserFactory, FastDirList, Job::safeMove, Doctor,
 * the integrity hook and the sitemap hook. Those bodies are now here, once.
 *
 * NULL therefore no longer means "no answer". It means what it says:
 *
 *   data(), object(), raw(), load(), meta()   NULL = there is no such document
 *   path()                                    FALSE = there is no such node
 *   find(), children(), langs(), links()      array() = nothing matched
 *   put(), link(), move(), …                  FALSE = the operation did nothing
 *
 * Three methods still return NULL unconditionally here, honestly: reindex(),
 * stats() and version() describe a derived index, and there is not one.
 *
 * ## Naming a node: `at`
 *
 * The database is keyed by the globally-unique node NAME, but a container can
 * be built from an explicit path (NodeFactory::loadFromRealPath /
 * fastLoadFromRealPath), and a caller holding such a path must not have its
 * question answered about a different directory that happens to share the
 * basename. Every name-keyed method therefore accepts `'at' => $path`, and a
 * caller that already holds the directory should pass it:
 *
 *   $db->langs($node->getName(), array('at' => $node->path))
 *
 * Here `at` is the answer — this class reads the directory it was handed and
 * resolves nothing. In FilesDbExt it is the identity check, made against the
 * index record the probe is already holding. It replaces the resolvesTo()
 * guard that used to sit in front of nine call sites for exactly this reason.
 *
 * ## Errors
 *
 * Failures raise FilesDbException; "not found" never does. See that class for
 * why it is not the extension's \QuantaDbException.
 *
 * @see files-db/docs/api-contract.md
 * @see files-db/docs/usage.md
 * @see files-db/docs/two-implementations.md
 */
class FilesDb {

  /**
   * Wall-clock seconds a fallback `find` walk may run before it is killed.
   *
   * This search only happens when the node index is unavailable. Uncapped it
   * walks the entire docroot, which on a large site is well over a hundred
   * thousand directories, and several of those in one request is enough to run
   * the request into max_execution_time.
   */
  const SEARCH_TIMEOUT_SECONDS = 5;

  /**
   * Whether this process has established that there is no `timeout` binary.
   *
   * Static, and deliberately so: it describes the IMAGE, not the tree. Whether
   * coreutils shipped /usr/bin/timeout cannot change between two calls in the
   * same process, so there is nothing here for forget() to invalidate — this is
   * not the second negative cache search() must not grow (see the comment
   * there). It exists only so a docroot search does not pay a doomed extra fork
   * per lookup once the first one has already told us the answer.
   *
   * @var bool
   */
  private static $search_timeout_missing = FALSE;

  /**
   * Sentinel for "this dot path is not in the document".
   *
   * A stored null is a value; an absent key is not, and where(['x' => NULL])
   * must tell them apart. See jsonEq().
   */
  const MISSING = "\0__filesdb_missing__";

  /**
   * @var Environment
   */
  protected $env;

  /**
   * The exception behind the most recent swallowed failure.
   *
   * @var \Throwable|null
   */
  protected $last_error = NULL;

  /**
   * Per-request resolver memo: node name => resolved directory.
   *
   * It holds the resolved DIRECTORY, not the tmp/cache shard symlink that
   * points at it. Storing the link was why a hit was never actually free: the
   * lookup still had to @readlink() it to learn the target, and readlink() is a
   * syscall PHP's stat cache does not cover, so it was paid on every call —
   * warm, cold, and repeatedly for the same name inside one request. Profiling
   * a single cold render of an admin list page counted 168 resolutions; every
   * repeat among them asked the kernel for something this request had already
   * resolved.
   *
   * Nothing re-validates a hit with is_dir() either. The value was resolved —
   * and, when it came off the symlink layer, validated — earlier in this same
   * request, and the paths that can invalidate it mid-request (Node::save(),
   * User::save()) call forget() to drop the entry. Re-stat'ing our own answer
   * would reintroduce exactly the syscall this memo exists to remove.
   *
   * @var array
   */
  protected $paths = array();

  /**
   * Per-request negative memo: node name => TRUE.
   *
   * @var array
   */
  protected $missing = array();

  /**
   * @param Environment $env
   *   The Environment this instance answers for.
   */
  public function __construct(Environment $env) {
    $this->env = $env;
  }

  /* ── Availability ──────────────────────────────────────────────────── */

  /**
   * Whether the native extension is serving this instance.
   *
   * A call site must not branch on this — every method answers either way.
   * It is here for Doctor, for the parity suite's discriminators, and for
   * anything reporting on the deployment.
   *
   * @return bool
   */
  public function available() {
    return FALSE;
  }

  /**
   * Whether a daemon-backed index is authoritative for this instance.
   *
   * Same rule: informational, not a branch. A miss from this class is as
   * definitive as a miss from a coherent index — it just costs more to reach.
   *
   * @return bool
   */
  public function coherent() {
    return FALSE;
  }

  /**
   * The exception behind the most recent swallowed failure, if any.
   *
   * @return \Throwable|null
   */
  public function lastError() {
    return $this->last_error;
  }

  /**
   * The properties that survive a serialize(), i.e. not $last_error.
   *
   * Under the native extension $last_error is a \QuantaDbException, and PHP
   * forbids serializing an exception object. Holding one therefore made every
   * object that can reach this instance — anything with an $env — fatal to
   * serialize, for the rest of the request, because of a failure this class
   * had already swallowed. The memos go too: both are per-request answers with
   * nothing to say to a later one.
   *
   * @return array
   */
  public function __sleep() {
    return array('env');
  }

  /**
   * The implementation and contract version, e.g. 'ext/1.3'.
   *
   * @return string|null
   *   NULL here: there is no index to version.
   */
  public function version() {
    return NULL;
  }

  /* ── Resolving a name ──────────────────────────────────────────────── */

  /**
   * Resolve a node name to its directory.
   *
   * This is Quanta's node resolver — the four-layer lookup that used to be
   * Environment::nodePath(), which is now a wrapper around it. Layers, in
   * order: the per-request memo, the tmp/cache shard symlink, and exec(find).
   * FilesDbExt puts the index in front of all three.
   *
   * @param string $name
   *   The node (folder) name. A path is accepted and its last segment used,
   *   which is what every caller of nodePath() relied on.
   * @param array $opts
   *   - 'link'   : also accept symlink candidates. The callers that ask for
   *                this readlink() the result themselves. The index cannot
   *                answer them (it resolves to real directories), so in
   *                FilesDbExt a 'link' search comes straight here.
   *   - 'search' : default TRUE. Pass FALSE to stop before the exec(find) —
   *                the cheap layers only. FALSE is then "not found cheaply",
   *                NOT a definitive absence, so it is not memoized and a later
   *                unrestricted call still searches. FastDirList asks for this:
   *                dodging that find is the entire reason it exists.
   *
   * @return string|false
   *   The directory, or FALSE when there is no such node.
   */
  public function path($name, $opts = array()) {
    $name = self::nameOf($name);

    if ($name == NULL) {
      return FALSE;
    }

    // A name starting with '-' is always a caller bug: it is "$x . '-suffix'"
    // with $x empty. No node can be named that way, but the lookup below still
    // pays for it — profiling a single cold page render found 67 of 168
    // resolutions were misses like this ('-description', '-shifts'), about 2.1s
    // of a 5.5s request, since exec(find) walks the whole docroot whether or
    // not it matches. Refuse them here, for every caller at once.
    if (substr($name, 0, 1) == '-') {
      return FALSE;
    }

    // Already searched for in this request and not found: don't search again.
    if (isset($this->missing[$name])) {
      return FALSE;
    }

    if (isset($this->paths[$name])) {
      return $this->paths[$name];
    }

    return $this->resolve($name, $opts);
  }

  /**
   * The node name in an argument that may be a path.
   *
   * Callers pass '/db/businesses/acme/', 'acme', or a whole request URI, and
   * have done since this was Environment::getLastPathSegment() — which lived
   * there only because the resolver did, and had no other caller.
   *
   * @param string $path
   *   A node name or a path ending in one.
   *
   * @return string|null
   *   The name, or NULL when there is none to find.
   */
  protected static function nameOf($path) {
    // Regular expression to match the last valid part of a URL path.
    $pattern = '/([^\/\?#]*[^\/\?#\.][^\/\?#]*|[^\/\?#]+)(?:[\?#]|$)/';

    if ($path == NULL) {
      return NULL;
    }
    if (preg_match($pattern, $path, $matches)) {
      return $matches[1];
    }
    return NULL;
  }

  /**
   * Drop everything this resolver knows about a name.
   *
   * Called after a write that can have moved or created the node — Node::save()
   * and User::save() do — so the rest of the request re-resolves it.
   *
   * The on-disk negative marker goes too, and that is load-bearing rather than
   * tidy: a create resolves the name FIRST (to find out there is nothing
   * there), which writes '__MISSING__' into the shard, and the node then
   * exists. Dropping only the in-memory memo would leave every later request
   * reading a marker that says the node it can see is not there.
   *
   * A positive marker is left alone: resolve() validates one with is_dir()
   * before trusting it, so a stale path costs a search and nothing more.
   *
   * @param string|null $name
   *   The name to forget, or NULL for all of them.
   */
  public function forget($name = NULL) {
    if (!$name) {
      $this->missing = $this->paths = array();
      return;
    }
    unset($this->missing[$name], $this->paths[$name]);
    $link = Cache::getStoredNodePath($this->env, $name, FALSE);
    if ($link && @readlink($link) === '__MISSING__') {
      @unlink($link);
    }
  }

  /**
   * Resolve a name that the memo could not answer.
   *
   * Split out of path() so FilesDbExt can put the index in front of it without
   * duplicating the memo, the '-' guard or the name parsing.
   *
   * @param string $name
   *   The node name, already parsed and known to be absent from both memos.
   * @param array $opts
   *   As path().
   *
   * @return string|false
   *   The directory, or FALSE.
   */
  protected function resolve($name, $opts = array()) {
    $link = !empty($opts['link']);
    // build=FALSE: this is the read side, so just compute the shard path and
    // stat the symlink. Creating the tmp/cache/a/b/c dirs here would run
    // is_dir/mkdir on every lookup (this method tops the profiler); the dirs
    // are created lazily by storeNodePath() below when a path is actually
    // cached.
    $node_path_link = Cache::getStoredNodePath($this->env, $name, FALSE);

    // Remember what the shard symlink already points at, so we can avoid
    // rewriting it below when it is already correct (the common warm case).
    $stored_target = @readlink($node_path_link);
    $node_path = $stored_target;

    if ($node_path === '__MISSING__') {
      return $this->markMissing($name);
    }

    // A target read off the shard symlink is only ever a cache: another request
    // wrote it, and the directory may be gone by now. Validate before trusting.
    if ($node_path !== FALSE && !is_dir($node_path)) {
      $node_path = FALSE;
    }

    // The index layer. Nothing here; FilesDbExt overrides it. It sits between
    // the shard and the search because that is the order that pays: in fallback
    // mode the shard symlink is cheaper than a per-process walk, and exec(find)
    // is dearer than either.
    if ($node_path == FALSE && !$link) {
      $indexed = $this->indexPath($name);
      if (is_string($indexed) && $indexed !== '') {
        $node_path = $indexed;
      }
      elseif ($indexed === FALSE) {
        return $this->markMissing($name);
      }
    }

    if ($node_path == FALSE && isset($opts['search']) && !$opts['search']) {
      // The caller asked for the cheap layers only. This is "not found without
      // searching", not an absence, so nothing is memoized — an unrestricted
      // lookup of the same name later still gets its search.
      return FALSE;
    }

    if ($node_path == FALSE) {
      // Use find to locate the node's directory in the file system.
      // TODO: run a sanity check that there is only one folder or throw error?
      $complete = TRUE;
      $results = $this->search($name, $complete);
      $found_folders = array();

      if (!$complete && empty($results)) {
        // The walk was cut short (the timeout fired, or it could not run), so
        // it never got far enough to say the node is absent. Answer FALSE for
        // this call and record nothing: markMissing() would blind the rest of
        // the request, and the '__MISSING__' marker below is worse still — it
        // is on disk, so it would blind every LATER request too, until a write
        // to that exact name happened to forget() it. A slow lookup that has to
        // be repeated is the right price for not inventing an absence.
        return FALSE;
      }

      if (empty($results)) {
        // The negative marker goes on disk (so the next request skips the find)
        // and into the memo (so the rest of this one does).
        Cache::storeNodePath($this->env, '__MISSING__', TRUE, $name);
        return $this->markMissing($name);
      }
      // Check that there are not duplicate folders. Don't count symlinks.
      foreach ($results as $i => $res) {
        if (is_dir($results[$i]) && ($link ? TRUE : !is_link($results[$i]))) {
          $found_folders[] = $results[$i];
          $node_path = $results[$i];
        }
      }

      if (empty($found_folders)) {
        // Same reasoning as above: the walk printed something, but nothing that
        // survived the is_dir/is_link filter. If it was truncated, the entry
        // that would have survived may simply not have been reached yet.
        if (!$complete) {
          return FALSE;
        }
        Cache::storeNodePath($this->env, '__MISSING__', TRUE, $name);
        return $this->markMissing($name);
      }

      if (count($found_folders) > 1) {
        new Message($this->env,
          t('Warning: there is more than one folder named !folder: <br/>!folds<br>Check integrity!',
            array(
              '!folder' => $name,
              '!folds' => var_export($found_folders, 1),
            )
          ));
      }
    }

    // Only (re)write the shard symlink when what's on disk isn't already
    // pointing at $node_path. On a warm request the symlink almost always
    // exists and is correct, so the old unconditional unlink+symlink (one per
    // node, every request) was pure waste — it put storeNodePath at the top of
    // the profiler. The stale case ($stored_target pointed at a now-deleted
    // dir, so it was reset to FALSE above and re-resolved) still rewrites
    // correctly.
    if ($stored_target !== $node_path) {
      Cache::storeNodePath($this->env, $node_path, TRUE, $name);
    }

    return $this->remember($name, $node_path);
  }

  /**
   * Ask the derived index for a name. There is none here.
   *
   * @param string $name
   *   The node name.
   *
   * @return string|false|null
   *   NULL — no index to ask. FilesDbExt returns the path, or FALSE when the
   *   index is authoritative and the node truly does not exist.
   */
  protected function indexPath($name) {
    return NULL;
  }

  /**
   * Search the docroot for a node directory.
   *
   * @param string $name
   *   The node name.
   * @param bool $complete
   *   Out. TRUE when the walk ran to the end, so an empty result really means
   *   "not in the docroot". FALSE when it was cut short — the timeout fired, or
   *   the command could not be run at all — in which case an empty result means
   *   nothing and the caller must NOT record an absence from it. resolve()
   *   writes a '__MISSING__' marker into the shard cache on an empty result,
   *   and that marker outlives the request: a truncated walk memoized as a miss
   *   would hide a node that is really there from every later request until
   *   something happened to forget() the name. Defaults to TRUE so an override
   *   that does not take the argument still reads as authoritative.
   *
   * @return array
   *   Candidate paths.
   */
  protected function search($name, &$complete = TRUE) {
    // TODO: cleaner way to exclude folders in _modules.
    $complete = TRUE;
    if (empty($name)) {
      return array();
    }
    // Repeated failed searches are already suppressed one level up: path()
    // short-circuits on $this->missing (see :245), which resolve() populates and
    // forget() invalidates. Do NOT add a second negative cache here -- an
    // earlier attempt at one bypassed forget(), so a node created or renamed
    // mid-request stayed invisible for the rest of it, and
    // tests/quanta/07_filesdb_writes.php caught exactly that.
    //
    // `timeout` bounds the walk so a degraded lookup cannot run into PHP's
    // max_execution_time and turn a slow page into a 500. This search walks the
    // whole docroot and only runs when the node index cannot answer, which is
    // exactly when it is least affordable.
    //
    // escapeshellarg because $name reaches here from request-derived node names,
    // and this path is the least-exercised one in the codebase. Quoting the
    // -not -path patterns also stops the shell globbing them against the cwd.
    $findcmd = 'find '
      . escapeshellarg($this->env->dir['docroot'] . '/')
      . ' -type d -name ' . escapeshellarg($name)
      . ' -not -path ' . escapeshellarg('*/_modules*')
      . ' -not -path ' . escapeshellarg('*.git*');

    $results = array();
    $status = 0;
    if (self::$search_timeout_missing) {
      exec($findcmd, $results, $status);
    }
    else {
      exec('timeout ' . self::SEARCH_TIMEOUT_SECONDS . ' ' . $findcmd, $results, $status);
      // 126/127 are the shell's "cannot execute" / "not found": `timeout` is
      // not in this image. It is in the debian-slim runtime (coreutils is
      // Essential) but not in every environment this code is run in, and an
      // unbounded walk is a far smaller problem than a resolver that finds
      // nothing at all — which is what silently swallowing a 127 would produce,
      // via the '__MISSING__' markers an empty result writes. So redo the walk
      // without the wrapper and stop reaching for it.
      if ($status === 126 || $status === 127) {
        self::$search_timeout_missing = TRUE;
        error_log('FilesDb: no usable `timeout` binary; the fallback node search '
          . 'runs unbounded from here on. Install coreutils in this image.');
        $results = array();
        exec($findcmd, $results, $status);
      }
    }

    // 0 is a clean walk. 1 is find's "I could not read some of it" — a
    // permission-denied subdirectory, say — and that has always counted as
    // authoritative here, because everything find COULD read, it did. Anything
    // else (124 from timeout, or the 126/127 the retry above could not fix)
    // means the walk did not finish, so its emptiness proves nothing.
    $complete = ($status === 0 || $status === 1);

    return $results;
  }

  /**
   * Record a resolved path in the memo and return it.
   *
   * @param string $name
   *   The node name.
   * @param string $path
   *   The resolved directory.
   *
   * @return string
   *   The path.
   */
  protected function remember($name, $path) {
    $this->paths[$name] = $path;
    return $path;
  }

  /**
   * Record a definitive absence and return it.
   *
   * @param string $name
   *   The node name.
   *
   * @return false
   */
  protected function markMissing($name) {
    $this->missing[$name] = TRUE;
    return FALSE;
  }

  /**
   * Whether a node exists.
   *
   * @param string $name
   *   The node name.
   *
   * @return bool
   */
  public function exists($name) {
    return $this->path($name) !== FALSE;
  }

  /**
   * Whether a node name really resolves to a given directory.
   *
   * Callers that already hold a $path normally pass it as `'at'` instead — this
   * stays for the few that want the question on its own.
   *
   * String compare first: the path almost always came from path() and is
   * already identical, so realpath() (two syscall-heavy resolutions) is only
   * the tie-breaker.
   *
   * @param string $name
   *   The node name.
   * @param string $path
   *   The directory the caller believes the node lives in.
   *
   * @return bool
   */
  public function resolvesTo($name, $path) {
    if (empty($name) || empty($path)) {
      return FALSE;
    }
    $resolved = $this->path($name);
    if (!is_string($resolved)) {
      return FALSE;
    }
    return $resolved === $path || realpath($resolved) === realpath($path);
  }

  /**
   * The directory a name-keyed call is about.
   *
   * `at` is taken at face value here: the caller holds the directory, so it IS
   * the node and nothing needs resolving. FilesDbExt checks it against the
   * index first and delegates back here when the two disagree — which is the
   * behaviour the old resolvesTo() call-site guards had.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   The caller's options; 'at' short-circuits the resolution.
   *
   * @return string|false
   *   The directory, or FALSE.
   */
  protected function dirFor($name, $opts = array()) {
    if (isset($opts['at']) && is_string($opts['at']) && $opts['at'] !== '') {
      return $opts['at'];
    }
    return $this->path($name);
  }

  /* ── Document reads ────────────────────────────────────────────────── */

  /**
   * The data document as an associative array.
   *
   * No language fallback: this reads exactly the language asked for, the same
   * as the contract's get(). Callers wanting Quanta's "translation first, then
   * neutral" order use load().
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return array|null
   *   The document, or NULL when there is none.
   */
  public function data($name, $lang = NULL, $opts = array()) {
    $raw = $this->raw($name, $lang, $opts);
    return ($raw === NULL) ? NULL : json_decode($raw, TRUE);
  }

  /**
   * The data document as a stdClass, nested shapes included.
   *
   * Note (object) data() is NOT the same thing: the cast converts only the top
   * level and leaves nested JSON objects as arrays, while Quanta reads them as
   * objects ($node->json->permissions->{$permission}).
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return object|null
   *   The document, or NULL when there is none.
   */
  public function object($name, $lang = NULL, $opts = array()) {
    $raw = $this->raw($name, $lang, $opts);
    return ($raw === NULL) ? NULL : (object) json_decode($raw);
  }

  /**
   * The stored bytes of the data document, exactly as they are on disk.
   *
   * Pair with putRaw() to rewrite a document without re-encoding it — PHP's
   * json_encode escapes '/' and non-ASCII, so a document that round-trips
   * through data()/put() comes back byte-different even though it is
   * value-identical.
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return string|null
   *   The raw JSON, or NULL when there is no such document.
   */
  public function raw($name, $lang = NULL, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return NULL;
    }
    $file = $path . '/' . $this->docName($lang);
    if (!is_file($file)) {
      return NULL;
    }
    $raw = @file_get_contents($file);
    return ($raw === FALSE) ? NULL : $raw;
  }

  /**
   * A node's document the way a node container needs it: language fallback,
   * path identity and the read itself, in one call.
   *
   * Composed, not primitive: data()/object()/raw() are unchanged and stay the
   * vocabulary for everything else. This exists because the trio is the hot
   * path — the three-call shape it replaces asked the index the same question
   * three times, and the first answer (an absolute path) existed only to be
   * compared once and dropped.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   - 'lang'     : the language to try first; NULL/'' = the neutral document.
   *   - 'fallback' : default TRUE — retry the neutral document when 'lang' has
   *                  none. That is Quanta's "translation first, then neutral".
   *   - 'at'       : the node's directory, when the caller holds it.
   *   - 'as'       : 'object' (default; nested stdClass, which is what
   *                  $node->json is) or 'array'.
   *
   * @return array|null
   *   array('json' => …, 'lang' => …, 'generation' => …), plus 'path' only when
   *   'at' was NOT passed. NULL when the node has no document in any language
   *   tried — which, now that both implementations always answer, is the only
   *   thing it means.
   *
   * @throws FilesDbException
   *   CORRUPT_JSON when a document is present but will not parse. Callers that
   *   want the historical "empty node" behaviour for a torn document catch it;
   *   see Node::loadJSON.
   */
  public function load($name, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return NULL;
    }
    $lang = isset($opts['lang']) ? $opts['lang'] : NULL;
    $fallback = !isset($opts['fallback']) || !empty($opts['fallback']);
    $as_array = (isset($opts['as']) && $opts['as'] === 'array');

    $tries = array($lang);
    if ($fallback && !empty($lang)) {
      $tries[] = NULL;
    }

    foreach ($tries as $try) {
      $raw = $this->raw($name, $try, array('at' => $path));
      if ($raw === NULL) {
        continue;
      }
      $json = $as_array ? json_decode($raw, TRUE) : json_decode($raw);
      if ($json === NULL && strtolower(trim($raw)) !== 'null') {
        throw new FilesDbException(
          'Could not decode ' . $path . '/' . $this->docName($try),
          FilesDbException::CORRUPT_JSON
        );
      }
      $result = array(
        'json' => $as_array ? (array) $json : (object) $json,
        'lang' => ($try === NULL) ? '' : $try,
        'generation' => 0,
      );
      if (!isset($opts['at'])) {
        $result['path'] = $path;
      }
      return $result;
    }
    return NULL;
  }

  /**
   * One field out of a data document, addressed by dot path.
   *
   * @param string $name
   *   The node name.
   * @param string $dot_path
   *   A dot path into the document, e.g. 'customer.country'.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return mixed|null
   *   The value, or NULL when the document or the path is absent.
   */
  public function value($name, $dot_path, $lang = NULL, $opts = array()) {
    return self::dig($this->data($name, $lang, $opts), $dot_path);
  }

  /**
   * The languages a node holds a document for.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return array
   *   Language codes; the neutral document is reported as ''.
   */
  public function langs($name, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return array();
    }
    $langs = array();
    if (is_file($path . '/data.json')) {
      $langs[] = '';
    }
    foreach ((array) glob($path . '/data_*.json') as $file) {
      $langs[] = substr(basename($file), strlen('data_'), -strlen('.json'));
    }
    return $langs;
  }

  /**
   * Whether a node holds a document in one specific language.
   *
   * Narrow on purpose. Composing this out of langs() would turn a single
   * is_file() into a glob(), on a call NodeFactory::load() makes for every node
   * it loads — one of the hottest questions in the system. Both
   * implementations can serve it in one probe, so it gets its own method.
   *
   * @param string $name
   *   The node name.
   * @param string $lang
   *   The language code; '' or NULL asks about the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return bool
   */
  public function hasLang($name, $lang, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return FALSE;
    }
    return is_file($path . '/' . $this->docName($lang));
  }

  /**
   * The data document's filename for a language.
   *
   * An empty language is the neutral document, not a 'data_.json' with an empty
   * code in the name — the two implementations have to name the same file for
   * the same call.
   *
   * @param string|null $lang
   *   The language code.
   *
   * @return string
   *   The filename.
   */
  protected function docName($lang) {
    return 'data' . (empty($lang) ? '' : '_' . $lang) . '.json';
  }

  /**
   * Walk a dot path into a decoded document.
   *
   * array_key_exists() on the assoc-array decoding gets JSON's list-vs-object
   * indexing right on its own: 'tags.0' finds the first element (PHP normalises
   * the numeric string to an int key), 'tags.x' and 'tags.9' find nothing, and
   * 'customer.0' finds nothing in an object keyed by strings.
   *
   * @param mixed $data
   *   The decoded document.
   * @param string $dot_path
   *   The dot path.
   * @param mixed $missing
   *   What to return when the path is not there. Pass self::MISSING to tell a
   *   missing key from a stored null; see jsonEq().
   *
   * @return mixed
   *   The value, or $missing.
   */
  protected static function dig($data, $dot_path, $missing = NULL) {
    foreach (explode('.', $dot_path) as $step) {
      if (!is_array($data) || !array_key_exists($step, $data)) {
        return $missing;
      }
      $data = $data[$step];
    }
    return $data;
  }

  /**
   * `where` equality, as the contract defines it.
   *
   * Equality only (api-contract.md §Queries), and typed: the documents are
   * JSON, so '10' is not 10 and 1 is not TRUE. The one crossing allowed is
   * between JSON's two number types, because a document holding 10 and one
   * holding 10.0 are the same number — and a caller has no way to control
   * which one json_decode hands back.
   *
   * A MISSING key matches nothing, not even NULL: 'the field is absent' and
   * 'the field is null' are different documents, and only the second one
   * answers a where(['opt' => NULL]).
   *
   * @param mixed $actual
   *   The value found in the document, or self::MISSING.
   * @param mixed $expected
   *   The value the caller asked for.
   *
   * @return bool
   */
  protected static function jsonEq($actual, $expected) {
    if ($actual === self::MISSING) {
      return FALSE;
    }
    if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
      return $actual == $expected;
    }
    return $actual === $expected;
  }

  /**
   * A node's metadata: path, father, mtime, langs.
   *
   * 'generation' and 'containers' are extension-only — a generation counter
   * belongs to an index, and containers cost an exec(find) here, which would be
   * a disaster for the caller that asks this per page (sitemap.hook.inc). Ask
   * links() for containers; it is cheap on the extension and honest here.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return array|null
   *   The metadata, or NULL when there is no such node.
   */
  public function meta($name, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path || !is_dir($path)) {
      return NULL;
    }
    $doc = $path . '/data.json';
    return array(
      'path' => $path,
      'father' => basename(dirname($path)),
      'mtime' => (int) (is_file($doc) ? filemtime($doc) : filemtime($path)),
      'langs' => $this->langs($name, array('at' => $path)),
    );
  }

  /* ── Structure ─────────────────────────────────────────────────────── */

  /**
   * The direct children of a node, by name.
   *
   * Takes Environment::scanDirectory()'s vocabulary, so call sites keep reading
   * the way they always did:
   *   - 'type'        : Environment::DIR_ALL | DIR_DIRS | DIR_FILES
   *   - 'symlinks'    : 'no' (real directories only) | 'only' (members only)
   *   - 'exclude_dirs': Environment::DIR_INACTIVE to hide '_' names (default)
   *   - 'at'          : the node's directory, when the caller holds it
   *
   * @param string $father
   *   The father node's name.
   * @param array $attributes
   *   scanDirectory() attributes.
   *
   * @return array
   *   Child node names, as a list.
   */
  public function children($father, $attributes = array()) {
    $path = $this->dirFor($father, $attributes);
    if (!$path) {
      return array();
    }
    unset($attributes['at']);
    // array_values(): scanDirectory() unset()s entries out of a scandir()
    // result, so it hands back holes in the keys while the extension returns a
    // list. Both implementations must be the same shape — a caller comparing
    // with ===, taking [0], or json_encode()ing the result (which turns a gappy
    // array into an OBJECT) would otherwise behave differently depending on
    // which one answered.
    return array_values($this->env->scanDirectory($path, $attributes));
  }

  /**
   * Whether a node has a given child.
   *
   * Narrow for the same reason as hasLang(): via children() this would be a
   * full scandir() plus an is_dir() per entry, where it is one is_dir().
   *
   * @param string $father
   *   The father node's name.
   * @param string $name
   *   The child's name.
   * @param array $opts
   *   - 'at' : the father's directory, when the caller holds it.
   *
   * @return bool
   */
  public function child($father, $name, $opts = array()) {
    if (empty($name)) {
      return FALSE;
    }
    $path = $this->dirFor($father, $opts);
    if (!$path) {
      return FALSE;
    }
    return is_dir($path . '/' . $name);
  }

  /**
   * The container nodes holding a symlink to a node.
   *
   * A container membership IS a symlink, so the non-symlink hit — the node's
   * own directory, which `find -L … -samefile` also matches because with -L a
   * symlink carries the target's inode — is skipped. That keeps this the same
   * answer the extension gives: real containers only, never the father.
   *
   * @param string $target
   *   The linked node's name.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *   - 'in' : limit the search to this subtree; default the docroot.
   *
   * @return array
   *   Container names.
   */
  public function links($target, $opts = array()) {
    $path = $this->dirFor($target, $opts);
    if (!$path) {
      return array();
    }
    $root = isset($opts['in']) ? $opts['in'] : $this->env->dir['docroot'];
    exec('find -L ' . escapeshellarg($root) . ' -samefile ' . escapeshellarg($path), $hits);

    $containers = array();
    foreach ((array) $hits as $hit) {
      if (!is_link($hit)) {
        continue;
      }
      $container = basename(dirname($hit));
      if ($container !== '' && $container !== '.') {
        $containers[$container] = TRUE;
      }
    }
    return array_keys($containers);
  }

  /* ── Queries ───────────────────────────────────────────────────────── */

  /**
   * Nodes matching a set of criteria.
   *
   * @param array $criteria
   *   father | lineage | in | where | name_prefix, all AND-ed. `where` is a map
   *   of dot path => scalar, equality only, exactly as the contract defines it
   *   (api-contract.md §Queries) — so a caller wanting a looser match still
   *   needs its own search, and that is a semantic gap, not a fallback.
   * @param array $opts
   *   return ('names'|'data'|'meta') | order_by | order | limit | offset | lang.
   *
   * @return array
   *   Results; array() when nothing matched.
   *
   * @throws FilesDbException
   *   BAD_ARGS for a criterion or option this cannot express.
   */
  public function find($criteria, $opts = array()) {
    $lang = isset($opts['lang']) ? $opts['lang'] : NULL;
    $names = $this->candidates($criteria);

    if (isset($criteria['name_prefix']) && $criteria['name_prefix'] !== '') {
      $prefix = $criteria['name_prefix'];
      $names = array_values(array_filter($names, function ($n) use ($prefix) {
        return strpos($n, $prefix) === 0;
      }));
    }

    if (!empty($criteria['where'])) {
      if (!is_array($criteria['where'])) {
        throw new FilesDbException('where must be a map', FilesDbException::BAD_ARGS);
      }
      $matched = array();
      foreach ($names as $name) {
        $data = $this->data($name, $lang);
        if (!is_array($data)) {
          continue;
        }
        foreach ($criteria['where'] as $field => $expected) {
          if (!self::jsonEq(self::dig($data, $field, self::MISSING), $expected)) {
            continue 2;
          }
        }
        $matched[] = $name;
      }
      $names = $matched;
    }

    $names = $this->order($names, $opts, $lang);

    $offset = isset($opts['offset']) ? (int) $opts['offset'] : 0;
    $limit = isset($opts['limit']) ? (int) $opts['limit'] : NULL;
    if ($offset || $limit !== NULL) {
      $names = array_slice($names, $offset, $limit);
    }

    $return = isset($opts['return']) ? $opts['return'] : 'names';
    switch ($return) {
      case 'names':
        return array_values($names);

      case 'data':
        $out = array();
        foreach ($names as $name) {
          $out[$name] = $this->data($name, $lang);
        }
        return $out;

      case 'meta':
        $out = array();
        foreach ($names as $name) {
          $out[$name] = $this->meta($name);
        }
        return $out;
    }
    throw new FilesDbException('Unknown return shape: ' . $return, FilesDbException::BAD_ARGS);
  }

  /**
   * How many nodes match a set of criteria.
   *
   * @param array $criteria
   *   As find().
   *
   * @return int
   */
  public function count($criteria) {
    return count($this->find($criteria, array('return' => 'names')));
  }

  /**
   * The candidate set a find() starts from.
   *
   * @param array $criteria
   *   The criteria.
   *
   * @return array
   *   Node names.
   *
   * @throws FilesDbException
   *   BAD_ARGS when no criterion bounds the search.
   */
  protected function candidates($criteria) {
    // include_hidden: the index holds '_' nodes and answers about them, so the
    // candidate set has to as well or the two implementations disagree.
    $all = array('type' => Environment::DIR_DIRS, 'exclude_dirs' => '');

    if (isset($criteria['in'])) {
      return $this->children($criteria['in'], $all);
    }
    if (isset($criteria['father'])) {
      return $this->children($criteria['father'], $all + array('symlinks' => 'no'));
    }
    if (isset($criteria['lineage'])) {
      return $this->descendants($criteria['lineage']);
    }
    if (isset($criteria['name_prefix'])) {
      return $this->descendants(basename($this->env->dir['docroot']), TRUE);
    }
    throw new FilesDbException(
      'find() needs father, lineage, in or name_prefix to bound the search',
      FilesDbException::BAD_ARGS
    );
  }

  /**
   * Every node under a node, at any depth.
   *
   * @param string $father
   *   The root of the walk.
   * @param bool $from_docroot
   *   Walk the docroot itself rather than resolving $father.
   *
   * @return array
   *   Node names.
   */
  protected function descendants($father, $from_docroot = FALSE) {
    $path = $from_docroot ? $this->env->dir['docroot'] : $this->path($father);
    if (!$path) {
      return array();
    }
    $found = array();
    $queue = array($path);
    while ($queue) {
      $dir = array_pop($queue);
      foreach ($this->env->scanDirectory($dir, array(
        'type' => Environment::DIR_DIRS,
        'exclude_dirs' => '',
        'symlinks' => 'no',
      )) as $child) {
        if (isset($found[$child])) {
          continue;
        }
        $found[$child] = TRUE;
        $queue[] = $dir . '/' . $child;
      }
    }
    return array_keys($found);
  }

  /**
   * Sort a candidate list per the contract's order_by / order.
   *
   * @param array $names
   *   The names to sort.
   * @param array $opts
   *   find() options.
   * @param string|null $lang
   *   The language 'json:' ordering reads.
   *
   * @return array
   *   The sorted names.
   *
   * @throws FilesDbException
   *   BAD_ARGS for an unknown order_by.
   */
  protected function order($names, $opts, $lang) {
    $by = isset($opts['order_by']) ? $opts['order_by'] : 'name';
    $desc = (isset($opts['order']) && strtolower($opts['order']) === 'desc');

    if ($by === 'name') {
      sort($names, SORT_STRING);
    }
    elseif ($by === 'mtime') {
      $keys = array();
      foreach ($names as $name) {
        $meta = $this->meta($name);
        $keys[$name] = is_array($meta) ? $meta['mtime'] : 0;
      }
      $names = $this->sortByKeys($names, $keys);
    }
    elseif (strpos($by, 'json:') === 0) {
      $dot = substr($by, strlen('json:'));
      $keys = array();
      foreach ($names as $name) {
        $keys[$name] = self::dig($this->data($name, $lang), $dot);
      }
      $names = $this->sortByKeys($names, $keys);
    }
    else {
      throw new FilesDbException('Unknown order_by: ' . $by, FilesDbException::BAD_ARGS);
    }

    return $desc ? array_reverse($names) : $names;
  }

  /**
   * Stable sort of names by a precomputed key map.
   *
   * @param array $names
   *   The names.
   * @param array $keys
   *   name => sort key.
   *
   * @return array
   *   The sorted names.
   */
  protected function sortByKeys($names, $keys) {
    usort($names, function ($a, $b) use ($keys) {
      if ($keys[$a] == $keys[$b]) {
        return strcmp($a, $b);
      }
      return ($keys[$a] < $keys[$b]) ? -1 : 1;
    });
    return $names;
  }

  /* ── Writes ────────────────────────────────────────────────────────── */

  /**
   * Replace a node's data document.
   *
   * With $opts['father'] this is a create: the name is reserved tree-wide and
   * the node directory is made under its father.
   *
   * @param string $name
   *   The node name.
   * @param array $data
   *   The document.
   * @param array $opts
   *   - 'father'    : declares create intent; required when the node does not
   *                   exist yet.
   *   - 'if_exists' : 'error' (default) | 'ignore' — what a create should do
   *                   when the name is already used somewhere else in the tree.
   *   - 'lang'      : the target language; NULL/'' is the neutral document.
   *   - 'at'        : the node's directory, when the caller holds it.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws FilesDbException
   *   EXISTS when a create hits a name already taken and if_exists is 'error';
   *   IO when the directory cannot be made or the document cannot be written.
   */
  public function put($name, array $data, $opts = array()) {
    return $this->write($name, json_encode($data), $opts);
  }

  /**
   * Write a pre-serialized document, byte for byte.
   *
   * The bytes are stored verbatim — nothing re-encodes them — so a document
   * stays stable on disk across writers. Invalid JSON raises BAD_ARGS before
   * anything is written.
   *
   * @param string $name
   *   The node name.
   * @param string $json
   *   The serialized document.
   * @param array $opts
   *   As put().
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws FilesDbException
   *   BAD_ARGS for invalid JSON; EXISTS and IO as put().
   */
  public function putRaw($name, $json, $opts = array()) {
    json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new FilesDbException(
        'putRaw() was given invalid JSON: ' . json_last_error_msg(),
        FilesDbException::BAD_ARGS
      );
    }
    return $this->write($name, $json, $opts);
  }

  /**
   * Read-modify-write a document.
   *
   * Unlocked here, where the extension holds the node's lock across the pair.
   * That is the one guarantee this implementation cannot make, and it is the
   * reason the extension exists — not a reason for the caller to branch.
   *
   * @param string $name
   *   The node name.
   * @param callable $fn
   *   Receives the current document, returns the new one.
   * @param array $opts
   *   lang | at.
   *
   * @return array|null
   *   The stored document, or NULL when there is no such node.
   */
  public function update($name, $fn, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return NULL;
    }
    $lang = isset($opts['lang']) ? $opts['lang'] : NULL;
    $current = $this->data($name, $lang, array('at' => $path));
    $new = call_user_func($fn, $current);
    if (!is_array($new)) {
      throw new FilesDbException('update() callback must return an array', FilesDbException::BAD_ARGS);
    }
    $this->write($name, json_encode($new), array('at' => $path, 'lang' => $lang));
    return $new;
  }

  /**
   * Serialize a document to disk.
   *
   * Durability sequence, matching the contract: write data.json.tmp.<pid>, then
   * rename() over the real name, so a reader can never observe a partial
   * document. The locking half of the contract's sequence is the extension's.
   *
   * @param string $name
   *   The node name.
   * @param string $json
   *   The serialized document.
   * @param array $opts
   *   As put().
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws FilesDbException
   *   EXISTS on a create whose name is taken; IO on a filesystem failure.
   */
  protected function write($name, $json, $opts = array()) {
    if (!empty($opts['father'])) {
      $path = $this->reserve($name, $opts);
    }
    else {
      // No create intent. The contract requires 'father' when the node does not
      // exist yet, so a name that does not resolve is a write to a node that is
      // not there — not an implicit creation.
      $path = $this->dirFor($name, $opts);
      if (!$path || !is_dir($path)) {
        return FALSE;
      }
    }
    if ($path === FALSE) {
      return FALSE;
    }

    $file = $path . '/' . $this->docName(isset($opts['lang']) ? $opts['lang'] : NULL);
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json) === FALSE) {
      throw new FilesDbException(
        'Impossibile scrivere il file: ' . $file . ' (Permesso negato o directory non scrivibile)',
        FilesDbException::IO
      );
    }
    if (!@rename($tmp, $file)) {
      @unlink($tmp);
      throw new FilesDbException(
        'Impossibile scrivere il file: ' . $file . ' (Permesso negato o directory non scrivibile)',
        FilesDbException::IO
      );
    }
    return TRUE;
  }

  /**
   * Reserve a node name and make its directory.
   *
   * Node names are globally unique, so create intent reserves the name
   * TREE-WIDE: one already in use somewhere else is EXISTS (api-contract.md
   * §Writes). The check is the resolver, which the per-request memo and the
   * shard symlink usually answer without a search.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   father | at | if_exists.
   *
   * @return string|false
   *   The node's directory, or FALSE when the father does not resolve.
   *
   * @throws FilesDbException
   *   EXISTS when the name is taken elsewhere and if_exists is not 'ignore';
   *   IO when the directory cannot be made.
   */
  protected function reserve($name, $opts) {
    $father_path = $this->path($opts['father']);
    if (!$father_path) {
      return FALSE;
    }
    // 'at' is where the caller intends the node to live, which is not always
    // father/name — a JSONDataContainer can be built from an explicit path.
    $path = (isset($opts['at']) && is_string($opts['at']) && $opts['at'] !== '')
      ? $opts['at']
      : $father_path . '/' . $name;

    $taken = $this->path($name);
    if ($taken !== FALSE && $taken !== $path && realpath($taken) !== realpath($path)) {
      $if_exists = isset($opts['if_exists']) ? $opts['if_exists'] : 'error';
      if ($if_exists !== 'ignore') {
        throw new FilesDbException(
          'The name ' . $name . ' is already taken, at ' . $taken,
          FilesDbException::EXISTS
        );
      }
    }

    if (!is_dir($path)) {
      if (!@mkdir($path, 0755, TRUE)) {
        throw new FilesDbException(
          'Impossibile creare la directory: ' . $path . ' (Permesso negato o percorso non valido)',
          FilesDbException::IO
        );
      }
      // The node exists now, and the lookup above has just recorded that it
      // does not — in the memo and, since it ran the search, in the shard.
      // Replace both with the truth rather than only dropping the lie: this
      // request and the next one both know where the new node is.
      $this->forget($name);
      $this->remember($name, $path);
      Cache::storeNodePath($this->env, $path, TRUE, $name);
    }
    return $path;
  }

  /**
   * Remove one language's document and leave the node in place.
   *
   * A node with no documents at all is a legal state: it still resolves and
   * still has children, and data() on it returns NULL.
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return bool
   *   TRUE when a document was removed, FALSE when there was none.
   */
  public function deleteDoc($name, $lang = NULL, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path) {
      return FALSE;
    }
    $file = $path . '/' . $this->docName($lang);
    if (!is_file($file)) {
      return FALSE;
    }
    return (bool) @unlink($file);
  }

  /**
   * Relocate a node under a new father, a new name, or both.
   *
   * The directory is renamed, so the whole subtree travels with it. Inbound
   * symlinks are NOT re-pointed here — the extension does that under the node's
   * lock, and doing it from PHP means an exec(find) over the docroot per move.
   * Doctor::checkBrokenLinks is the repair for what this leaves dangling, and
   * it was written for exactly this reason.
   *
   * @param string $name
   *   The node name.
   * @param string|null $new_father
   *   The destination father, or NULL to rename in place.
   * @param array $opts
   *   name | if_exists ('error' | 'replace') | at.
   *
   * @return bool
   *   TRUE on success, FALSE when $name does not resolve.
   *
   * @throws FilesDbException
   *   EXISTS when the destination is occupied and if_exists is 'error';
   *   BAD_ARGS when the new father is inside the node's own subtree.
   */
  public function move($name, $new_father = NULL, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path || !is_dir($path)) {
      return FALSE;
    }
    $new_name = isset($opts['name']) ? $opts['name'] : $name;
    if ($new_father === NULL) {
      $dest = dirname($path) . '/' . $new_name;
    }
    else {
      $father_path = $this->path($new_father);
      if (!$father_path) {
        return FALSE;
      }
      if (strpos($father_path . '/', $path . '/') === 0) {
        throw new FilesDbException(
          'Cannot move ' . $name . ' inside its own subtree',
          FilesDbException::BAD_ARGS
        );
      }
      $dest = $father_path . '/' . $new_name;
    }

    if ($dest === $path) {
      return TRUE;
    }
    if (file_exists($dest)) {
      $if_exists = isset($opts['if_exists']) ? $opts['if_exists'] : 'error';
      if ($if_exists !== 'replace') {
        throw new FilesDbException('Destination exists: ' . $dest, FilesDbException::EXISTS);
      }
      // -T so the destination is an exact name, not a parent to nest under.
      exec('rm -rf ' . escapeshellarg($dest));
    }
    if (!@rename($path, $dest)) {
      throw new FilesDbException('Could not move ' . $path . ' to ' . $dest, FilesDbException::IO);
    }
    $this->forget($name);
    $this->forget($new_name);
    return TRUE;
  }

  /**
   * Move a node to the trashbin.
   *
   * There is no hard delete and no trashbin management in the contract, so
   * high-churn cleanup that must actually free space stays on the filesystem.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   - 'at' : the node's directory, when the caller holds it.
   *
   * @return bool
   *   TRUE on success, FALSE when there is no such node.
   *
   * @throws FilesDbException
   *   IO when the trashbin cannot be made or the node cannot be moved into it.
   */
  public function delete($name, $opts = array()) {
    $path = $this->dirFor($name, $opts);
    if (!$path || !is_dir($path)) {
      return FALSE;
    }
    // The same trashbin the extension uses: quanta_db.trashbin_dir is pointed
    // at $env->dir['trashbin'], so recovery works the same way either way.
    $destination = $this->env->dir['trashbin'] . '/' . time();
    if (!is_dir($destination) && !@mkdir($destination, 0777, TRUE)) {
      throw new FilesDbException(
        t('Failed to create destination folder.') . ' ' . $destination,
        FilesDbException::IO
      );
    }
    // -T treats destination as exact name, not parent directory.
    exec('mv -T ' . escapeshellarg($path) . ' ' . escapeshellarg($destination . '/' . basename($path))
      . ' 2>/dev/null', $out, $status);
    if ($status != 0 && is_dir($path)) {
      throw new FilesDbException('Could not trash ' . $path, FilesDbException::IO);
    }
    $this->forget($name);
    return TRUE;
  }

  /* ── Container membership ──────────────────────────────────────────── */

  /**
   * Add a node to a container.
   *
   * @param string $target
   *   The node to link.
   * @param string $container
   *   The container node.
   * @param array $opts
   *   - 'if_exists' : 'error' (default) | 'ignore' | 'override'.
   *   - 'name'      : the link's filename, when it is not the target's name.
   *
   * @return bool
   *   TRUE when the link is in place.
   *
   * @throws FilesDbException
   *   BAD_ARGS when the target or the container does not resolve;
   *   EXISTS when the link is already there and if_exists is 'error';
   *   IO when the symlink cannot be made.
   */
  public function link($target, $container, $opts = array()) {
    $target_path = $this->path($target);
    if (!$target_path) {
      throw new FilesDbException("target node '$target' not found", FilesDbException::BAD_ARGS);
    }
    $container_path = $this->path($container);
    if (!$container_path) {
      throw new FilesDbException("container node '$container' not found", FilesDbException::BAD_ARGS);
    }
    $link = $container_path . '/' . (isset($opts['name']) ? $opts['name'] : $target);

    // is_link(), not file_exists(): a link whose target has gone still occupies
    // the name, and repairing exactly that is what 'override' is for.
    if (is_link($link)) {
      $if_exists = isset($opts['if_exists']) ? $opts['if_exists'] : 'error';
      if ($if_exists === 'error') {
        throw new FilesDbException('Link exists: ' . $link, FilesDbException::EXISTS);
      }
      if ($if_exists !== 'override') {
        return TRUE;
      }
      if (!@unlink($link)) {
        throw new FilesDbException('Could not replace ' . $link, FilesDbException::IO);
      }
    }
    if (!@symlink($target_path, $link)) {
      throw new FilesDbException('Could not link ' . $target . ' into ' . $container, FilesDbException::IO);
    }
    return TRUE;
  }

  /**
   * Remove a node from a container.
   *
   * @param string $target
   *   The linked node.
   * @param string $container
   *   The container node.
   * @param array $opts
   *   if_not_exists ('error' | 'ignore') | name.
   *
   * @return bool
   *   TRUE when a link was removed, FALSE when there was none.
   *
   * @throws FilesDbException
   *   IO when there is no such link and if_not_exists is 'error', and when the
   *   symlink cannot be removed. One code for both, because that is the one the
   *   contract's implementation raises — a caller that needs to tell them apart
   *   passes 'ignore' and reads the FALSE, which is what NodeFactory does.
   */
  public function unlink($target, $container, $opts = array()) {
    $container_path = $this->path($container);
    $link = $container_path
      ? $container_path . '/' . (isset($opts['name']) ? $opts['name'] : $target)
      : NULL;

    // is_link(), not file_exists(): a link whose target has gone is exactly
    // what Doctor::checkBrokenLinks is repairing, and it must be removable.
    if ($link === NULL || !is_link($link)) {
      $if_not_exists = isset($opts['if_not_exists']) ? $opts['if_not_exists'] : 'error';
      if ($if_not_exists === 'error') {
        throw new FilesDbException(
          "'$target' is not linked in '$container'",
          FilesDbException::IO
        );
      }
      return FALSE;
    }
    if (!@unlink($link)) {
      throw new FilesDbException('Could not unlink ' . $link, FilesDbException::IO);
    }
    return TRUE;
  }

  /**
   * Move a node between two containers.
   *
   * Atomic under the node's lock in the extension, so a reader never sees it in
   * zero or two of them. Here it is an unlink followed by a link, which cannot
   * promise that — see update() for the same caveat and the same reasoning.
   *
   * @param string $target
   *   The linked node.
   * @param string $from_container
   *   The container to leave.
   * @param string $to_container
   *   The container to join.
   * @param array $opts
   *   Unused.
   *
   * @return bool
   *   TRUE on success.
   */
  public function relink($target, $from_container, $to_container, $opts = array()) {
    $this->unlink($target, $from_container, array('if_not_exists' => 'ignore'));
    return $this->link($target, $to_container, array('if_exists' => 'ignore'));
  }

  /* ── Maintenance ───────────────────────────────────────────────────── */

  /**
   * Rebuild the derived index from the filesystem.
   *
   * @param string|null $subtree
   *   Limit the rebuild to one subtree.
   *
   * @return array|null
   *   NULL here: there is no derived index to rebuild, and callers print their
   *   report only when there is one (see Doctor::checkBrokenLinks).
   */
  public function reindex($subtree = NULL) {
    return NULL;
  }

  /**
   * Implementation counters and configuration.
   *
   * @return array|null
   *   NULL here: there are no counters to report.
   */
  public function stats() {
    return NULL;
  }

}
