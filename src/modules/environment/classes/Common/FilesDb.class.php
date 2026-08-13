<?php

namespace Quanta\Common;

/**
 * Single entry point to the file-based node database.
 *
 * Quanta stores every node as a directory holding data*.json. That is the
 * database and it stays the database. Two implementations answer questions
 * about it:
 *
 *   - the `quanta_db` native extension (files-db/docs/api-contract.md), which
 *     serves lookups from a shared-memory projection of the tree and makes
 *     writes locked and atomic;
 *   - the legacy filesystem code (exec find, scandir, file_get_contents,
 *     unlocked fopen), which is always correct and always available.
 *
 * This class owns the choice between them so that call sites do not. Before it
 * existed, every wired call site repeated the same three things — a
 * class_exists() probe, a try/catch, and the sites/<alias> docroot rewrite —
 * in Environment, FastDirList, UserFactory, JSONDataContainer and NodeFactory.
 *
 * ## Two kinds of method
 *
 * Some operations have one obvious legacy implementation, so this class can
 * carry it and simply answer the question:
 *
 *   path(), data(), object(), raw(), value(), langs(), children()
 *
 * The rest do not: the legacy behaviour of a write is whatever the call site
 * did, including the user-facing Messages it emits. Those methods return NULL
 * to mean "the extension could not answer — run your own legacy code":
 *
 *   find(), count(), put(), putRaw(), update(), deleteDoc(), move(), delete(),
 *   link(), unlink(), relink(), reindex(), stats()
 *
 * NULL is deliberately distinct from an empty result. find() returning array()
 * means "no such nodes"; find() returning NULL means "no answer" — collapsing
 * the two would make a caller skip its fallback and silently report nothing.
 *
 * ## Errors
 *
 * By default any QuantaDbException (and anything else the extension throws) is
 * swallowed and the method reports "no answer", so an extension problem can
 * never be worse than not having the extension. Pass $opts['strict'] = TRUE on
 * a write to let QuantaDbException through instead — that is how a caller opts
 * into the contract's guarantees, most usefully the EXISTS raised by
 * put(..., array('father' => ...)) when a name is already taken anywhere in
 * the tree. lastError() holds the exception from the most recent swallowed
 * failure either way.
 *
 * @see files-db/docs/api-contract.md
 * @see files-db/docs/usage.md
 */
class FilesDb {

  /**
   * @var Environment
   */
  protected $env;

  /**
   * The extension's configured root, '' when the extension is not usable.
   *
   * @var string|null
   */
  protected $ext_root = NULL;

  /**
   * Whether the daemon-backed projection is authoritative. Resolved once.
   *
   * @var bool|null
   */
  protected $is_coherent = NULL;

  /**
   * The exception behind the most recent swallowed failure.
   *
   * @var \Throwable|null
   */
  protected $last_error = NULL;

  /**
   * @param Environment $env
   *   The Environment this instance answers for.
   */
  public function __construct(Environment $env) {
    $this->env = $env;
  }

  /* ── Availability ──────────────────────────────────────────────────── */

  /**
   * Whether the extension is loaded and configured.
   *
   * @return bool
   */
  public function available() {
    if ($this->ext_root === NULL) {
      // No autoload: an extension registers its classes at startup, so if
      // QuantaDb is not here already it is not coming — and asking the
      // autoloader for a class with no namespace is what made it warn.
      $this->ext_root = class_exists('QuantaDb', FALSE)
        ? rtrim((string) (ini_get('quanta_db.root') ?: getenv('QUANTA_DB_ROOT')), '/')
        : '';
    }
    return $this->ext_root !== '';
  }

  /**
   * Whether the extension's index is currently authoritative.
   *
   * When TRUE, a lookup that finds nothing is a definitive absence and the
   * caller may skip its legacy search. When FALSE the extension is serving
   * from a per-process walk snapshot, so only a positive answer means anything.
   *
   * @return bool
   */
  public function coherent() {
    if ($this->is_coherent === NULL) {
      try {
        $this->is_coherent = $this->available() && \QuantaDb::coherent();
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
        $this->is_coherent = FALSE;
      }
    }
    return $this->is_coherent;
  }

  /**
   * The exception behind the most recent swallowed failure, if any.
   *
   * @return \Throwable|null
   */
  public function lastError() {
    return $this->last_error;
  }

  /* ── Lookups ───────────────────────────────────────────────────────── */

  /**
   * Resolve a node name to its path.
   *
   * Three-state, so a caller can tell a definitive absence from "unsure":
   *   - string : the node path, under the current host's docroot.
   *   - FALSE  : the node does not exist AND the index is authoritative, so a
   *              legacy search would only confirm it.
   *   - NULL   : the extension is absent, errored, or not authoritative —
   *              the caller must fall back to its own lookup.
   *
   * @param string $name
   *   The node (folder) name.
   *
   * @return string|false|null
   */
  public function path($name) {
    if (!$this->available()) {
      return NULL;
    }
    // coherent() before the lookup: it decides what a miss means, and resolving
    // it first keeps that decision out of the per-lookup path.
    $coherent = $this->coherent();
    try {
      $path = \QuantaDb::path($name);
    }
    catch (\Throwable $e) {
      $this->last_error = $e;
      return NULL;
    }
    if ($path === NULL) {
      return $coherent ? FALSE : NULL;
    }
    return $this->toDocroot($path);
  }

  /**
   * Whether a node exists.
   *
   * @param string $name
   *   The node name.
   *
   * @return bool|null
   *   TRUE/FALSE, or NULL when the extension cannot answer.
   */
  public function exists($name) {
    $path = $this->path($name);
    if ($path === NULL) {
      return NULL;
    }
    return $path !== FALSE;
  }

  /**
   * Map an extension path onto the current host's docroot.
   *
   * sites/<alias> hosts are symlinks to the canonical site directory the
   * extension is rooted at, so a raw extension path would not compare equal to
   * anything the Environment built.
   *
   * @param string $path
   *   A path as the extension reports it.
   *
   * @return string
   *   The path under the current host's docroot.
   */
  protected function toDocroot($path) {
    $docroot = $this->env->dir['docroot'];
    if ($docroot !== $this->ext_root && strpos($path, $this->ext_root . '/') === 0) {
      return $docroot . substr($path, strlen($this->ext_root));
    }
    return $path;
  }

  /* ── Document reads ────────────────────────────────────────────────── */

  /**
   * The data document as an associative array.
   *
   * No language fallback: this reads exactly the language asked for, the same
   * as the contract's get(). Callers wanting Quanta's "translation first, then
   * neutral" order ask twice, in that order.
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   *
   * @return array|null
   *   The document, or NULL when there is none.
   */
  public function data($name, $lang = NULL) {
    if ($this->available()) {
      try {
        return \QuantaDb::get($name, $lang);
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
      }
    }
    $raw = $this->legacyRaw($name, $lang);
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
   *
   * @return object|null
   *   The document, or NULL when there is none.
   */
  public function object($name, $lang = NULL) {
    if ($this->available()) {
      try {
        return \QuantaDb::getObject($name, $lang);
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
      }
    }
    $raw = $this->legacyRaw($name, $lang);
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
   *
   * @return string|null
   *   The raw JSON, or NULL when there is no such document.
   */
  public function raw($name, $lang = NULL) {
    if ($this->available()) {
      try {
        return \QuantaDb::getRaw($name, $lang);
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
      }
    }
    return $this->legacyRaw($name, $lang);
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
   *
   * @return mixed|null
   *   The value, or NULL when the document or the path is absent.
   */
  public function value($name, $dot_path, $lang = NULL) {
    $data = $this->data($name, $lang);
    if (!is_array($data)) {
      return NULL;
    }
    foreach (explode('.', $dot_path) as $step) {
      if (!is_array($data) || !array_key_exists($step, $data)) {
        return NULL;
      }
      $data = $data[$step];
    }
    return $data;
  }

  /**
   * The languages a node holds a document for.
   *
   * @param string $name
   *   The node name.
   *
   * @return array
   *   Language codes; the neutral document is reported as ''.
   */
  public function langs($name) {
    if ($this->available()) {
      try {
        $meta = \QuantaDb::meta($name);
        if (is_array($meta) && isset($meta['langs'])) {
          return $meta['langs'];
        }
        if ($meta === NULL && $this->coherent()) {
          return array();
        }
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
      }
    }
    $path = $this->nodePath($name);
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
   * Read a data document straight off the filesystem.
   *
   * @param string $name
   *   The node name.
   * @param string|null $lang
   *   The language, or NULL for the neutral document.
   *
   * @return string|null
   *   The raw JSON, or NULL when there is no such file.
   */
  protected function legacyRaw($name, $lang = NULL) {
    $path = $this->nodePath($name);
    if (!$path) {
      return NULL;
    }
    $file = $path . '/data' . (empty($lang) ? '' : '_' . $lang) . '.json';
    if (!is_file($file)) {
      return NULL;
    }
    $raw = @file_get_contents($file);
    return ($raw === FALSE) ? NULL : $raw;
  }

  /**
   * Resolve a node name to a path by whatever means are available.
   *
   * Unlike path() this is two-state — it is for the legacy bodies in this
   * class, which need a path or nothing.
   *
   * @param string $name
   *   The node name.
   *
   * @return string|false
   *   The path, or FALSE.
   */
  protected function nodePath($name) {
    $path = $this->path($name);
    if (is_string($path)) {
      return $path;
    }
    if ($path === FALSE) {
      return FALSE;
    }
    return $this->env->nodePath($name);
  }

  /* ── Structure ─────────────────────────────────────────────────────── */

  /**
   * The direct children of a node, by name.
   *
   * Takes Environment::scanDirectory()'s vocabulary so call sites keep reading
   * the way they always did:
   *   - 'type'        : Environment::DIR_ALL | DIR_DIRS | DIR_FILES
   *   - 'symlinks'    : 'no' (real directories only) | 'only' (members only)
   *   - 'exclude_dirs': Environment::DIR_INACTIVE to hide '_' names (default)
   *
   * The index only answers about NODES, so it can serve this question only
   * when the caller is asking about nodes:
   *
   *   - 'symlinks' => 'no'   → real child directories
   *   - 'symlinks' => 'only' → symlinked members
   *   - DIR_DIRS             → both, which is what scanDirectory returns for it
   *
   * DIR_ALL and DIR_FILES also return the plain files sitting in the node
   * directory (tpl.html, data.json, uploads), which are deliberately not
   * indexed, so they stay on the legacy scan. So does any exclude_dirs other
   * than Quanta's '_' convention, which the index has no way to express.
   *
   * @param string $father
   *   The father node's name.
   * @param array $attributes
   *   scanDirectory() attributes.
   *
   * @return array
   *   Child node names.
   */
  public function children($father, $attributes = array()) {
    $type = isset($attributes['type']) ? $attributes['type'] : Environment::DIR_ALL;
    $symlinks = isset($attributes['symlinks']) ? $attributes['symlinks'] : NULL;
    $exclude = array_key_exists('exclude_dirs', $attributes)
      ? $attributes['exclude_dirs']
      : Environment::DIR_INACTIVE;

    $ext_type = NULL;
    if ($symlinks === 'no') {
      $ext_type = 'dirs';
    }
    elseif ($symlinks === 'only') {
      $ext_type = 'links';
    }
    elseif ($type === Environment::DIR_DIRS) {
      $ext_type = 'all';
    }

    $expressible = ($ext_type !== NULL)
      && ($exclude === Environment::DIR_INACTIVE || empty($exclude));

    if ($this->available() && $expressible) {
      try {
        return \QuantaDb::children($father, array(
          'type' => $ext_type,
          'include_hidden' => empty($exclude),
        ));
      }
      catch (\Throwable $e) {
        $this->last_error = $e;
      }
    }

    $path = $this->nodePath($father);
    if (!$path) {
      return array();
    }
    return $this->env->scanDirectory($path, $attributes);
  }

  /**
   * The container nodes holding a symlink to a node.
   *
   * @param string $target
   *   The linked node's name.
   *
   * @return array|null
   *   Container names, or NULL when the extension cannot answer.
   */
  public function links($target) {
    return $this->call('links', array($target));
  }

  /* ── Queries ───────────────────────────────────────────────────────── */

  /**
   * Nodes matching a set of criteria.
   *
   * @param array $criteria
   *   father | lineage | in | where | name_prefix, all AND-ed.
   * @param array $opts
   *   return ('names'|'data'|'meta') | order_by | order | limit | offset | lang.
   *
   * @return array|null
   *   Results, or NULL when the extension cannot answer — which is NOT the
   *   same as array() for "nothing matched".
   */
  public function find($criteria, $opts = array()) {
    return $this->call('find', array($criteria, $opts));
  }

  /**
   * How many nodes match a set of criteria.
   *
   * @param array $criteria
   *   As find().
   *
   * @return int|null
   *   The count, or NULL when the extension cannot answer.
   */
  public function count($criteria) {
    return $this->call('count', array($criteria));
  }

  /**
   * A node's metadata: path, father, mtime, generation, langs, containers.
   *
   * @param string $name
   *   The node name.
   *
   * @return array|null
   *   The metadata, or NULL.
   */
  public function meta($name) {
    return $this->call('meta', array($name));
  }

  /* ── Writes ────────────────────────────────────────────────────────── */

  /**
   * Replace a node's data document.
   *
   * With $opts['father'] this is a create: the node directory is reserved
   * atomically, and EXISTS is raised when the name is already taken anywhere
   * in the tree. Pass $opts['strict'] = TRUE to see that exception rather than
   * a NULL return.
   *
   * @param string $name
   *   The node name.
   * @param array $data
   *   The document.
   * @param array $opts
   *   father | lang | strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function put($name, array $data, $opts = array()) {
    return $this->call('put', array($name, $data, $this->writeOpts($opts)), $opts);
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
   *   father | lang | strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function putRaw($name, $json, $opts = array()) {
    return $this->call('putRaw', array($name, $json, $this->writeOpts($opts)), $opts);
  }

  /**
   * Read-modify-write a document under the node's lock.
   *
   * @param string $name
   *   The node name.
   * @param callable $fn
   *   Receives the current document, returns the new one.
   * @param array $opts
   *   lang | strict.
   *
   * @return array|null
   *   The stored document, or NULL when the extension cannot answer.
   */
  public function update($name, $fn, $opts = array()) {
    return $this->call('update', array($name, $fn, $this->writeOpts($opts)), $opts);
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
   *   strict.
   *
   * @return bool|null
   *   TRUE when a document was removed, FALSE when there was none, NULL when
   *   the extension cannot answer.
   */
  public function deleteDoc($name, $lang = NULL, $opts = array()) {
    return $this->call('deleteDoc', array($name, $lang), $opts);
  }

  /**
   * Relocate a node under a new father, a new name, or both.
   *
   * The directory is renamed, so the whole subtree travels with it, and every
   * inbound symlink is re-pointed — which a bare rename() does not do, leaving
   * every container membership dangling.
   *
   * @param string $name
   *   The node name.
   * @param string|null $new_father
   *   The destination father, or NULL to rename in place.
   * @param array $opts
   *   name | if_exists ('error' | 'replace') | strict.
   *
   * @return bool|null
   *   TRUE on success, FALSE when $name does not resolve, NULL when the
   *   extension cannot answer.
   */
  public function move($name, $new_father = NULL, $opts = array()) {
    return $this->call('move', array($name, $new_father, $this->writeOpts($opts)), $opts);
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
   *   strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function delete($name, $opts = array()) {
    return $this->call('delete', array($name), $opts);
  }

  /**
   * Add a node to a container.
   *
   * @param string $target
   *   The node to link.
   * @param string $container
   *   The container node.
   * @param array $opts
   *   if_exists ('error' | 'ignore') | strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function link($target, $container, $opts = array()) {
    return $this->call('link', array($target, $container, $this->writeOpts($opts)), $opts);
  }

  /**
   * Remove a node from a container.
   *
   * @param string $target
   *   The linked node.
   * @param string $container
   *   The container node.
   * @param array $opts
   *   if_not_exists ('error' | 'ignore') | strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function unlink($target, $container, $opts = array()) {
    return $this->call('unlink', array($target, $container, $this->writeOpts($opts)), $opts);
  }

  /**
   * Move a node between two containers atomically.
   *
   * Under the node's lock, so a reader never sees it in zero or two of them —
   * which an unlink followed by a link cannot promise.
   *
   * @param string $target
   *   The linked node.
   * @param string $from_container
   *   The container to leave.
   * @param string $to_container
   *   The container to join.
   * @param array $opts
   *   strict.
   *
   * @return bool|null
   *   TRUE on success, or NULL when the extension cannot answer.
   */
  public function relink($target, $from_container, $to_container, $opts = array()) {
    return $this->call('relink', array($target, $from_container, $to_container), $opts);
  }

  /* ── Maintenance ───────────────────────────────────────────────────── */

  /**
   * Rebuild the derived index from the filesystem.
   *
   * Safe at any time, including under traffic. Call it after something has
   * rewritten the tree from outside PHP (a restore, an rsync), and to repair
   * dangling symlinks left by a crash in the middle of a move().
   *
   * @param string|null $subtree
   *   Limit the rebuild to one subtree.
   *
   * @return array|null
   *   nodes | links | seconds, or NULL when the extension cannot answer.
   */
  public function reindex($subtree = NULL) {
    return $this->call('reindex', array($subtree));
  }

  /**
   * Implementation counters and configuration.
   *
   * @return array|null
   *   The stats, or NULL when the extension cannot answer.
   */
  public function stats() {
    return $this->call('stats', array());
  }

  /**
   * The implementation and contract version, e.g. 'ext/1.3'.
   *
   * @return string|null
   *   The version, or NULL when the extension is not available.
   */
  public function version() {
    return $this->call('version', array());
  }

  /* ── Plumbing ──────────────────────────────────────────────────────── */

  /**
   * Call an extension method, reporting NULL when it cannot answer.
   *
   * @param string $method
   *   The QuantaDb static method name.
   * @param array $args
   *   Positional arguments.
   * @param array $opts
   *   The caller's options; 'strict' re-throws QuantaDbException.
   *
   * @return mixed|null
   *   The result, or NULL.
   */
  protected function call($method, array $args, $opts = array()) {
    if (!$this->available()) {
      return NULL;
    }
    try {
      return call_user_func_array(array('QuantaDb', $method), $args);
    }
    catch (\QuantaDbException $e) {
      $this->last_error = $e;
      if (!empty($opts['strict'])) {
        throw $e;
      }
      return NULL;
    }
    catch (\Throwable $e) {
      // Anything else — a stale .so without this method, a bad argument —
      // is reported the same way: no answer, use the legacy path.
      $this->last_error = $e;
      return NULL;
    }
  }

  /**
   * Strip this class's own options before they reach the extension.
   *
   * @param array $opts
   *   The caller's options.
   *
   * @return array
   *   The options the contract defines.
   */
  protected function writeOpts($opts) {
    unset($opts['strict']);
    return $opts;
  }

}
