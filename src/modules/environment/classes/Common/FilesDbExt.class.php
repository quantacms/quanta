<?php

namespace Quanta\Common;

require_once __DIR__ . '/FilesDb.class.php';

/**
 * The node database, served by the `quanta_db` native extension.
 *
 * Same API as FilesDb, same answers — from a shared-memory projection of the
 * tree instead of the filesystem, with writes that are locked and atomic.
 * Environment::db() instantiates this class when the extension is loaded and
 * FilesDb when it is not; nothing else in Quanta names it.
 *
 * ## The shape of every method here
 *
 * Ask the extension. If it answers, return. Otherwise `return parent::…()`.
 * That last line is the whole reason this is a subclass rather than a sibling:
 * the choice between the two implementations is not made once per process, it
 * is made per call, because a loaded and coherent extension still cannot answer
 * everything —
 *
 *   - **Inexpressible questions.** children() with DIR_ALL / DIR_FILES, or an
 *     exclude_dirs other than Quanta's '_', is asking about files (tpl.html,
 *     data.json, uploads) that the index deliberately does not hold. links()
 *     scoped to a subtree is not in the contract either.
 *   - **An incoherent index.** With no daemon the extension serves from a
 *     per-process filesystem walk, so a *positive* answer is trustworthy but a
 *     miss proves nothing.
 *   - **A name that is not this node.** The index is keyed by the globally
 *     unique node NAME, but a container can be built from an explicit path
 *     (NodeFactory::loadFromRealPath). `at` catches that, and the answer for a
 *     directory the index does not agree about has to come off the disk.
 *   - **A runtime failure.** A daemon that died mid-request, a stale .so
 *     missing a method. An extension problem must never be worse than not
 *     having the extension.
 *
 * ## Errors
 *
 * \QuantaDbException is wrapped in Quanta\Common\FilesDbException, code
 * preserved, so a call site catches one class either way — and so a `catch`
 * clause naming it is not a fatal on a host with no .so. Failures of a WRITE
 * are re-thrown, not retried against the filesystem: a locked, half-completed
 * write is not something to paper over by doing it again unlocked.
 *
 * @see files-db/docs/api-contract.md
 * @see files-db/docs/two-implementations.md
 */
class FilesDbExt extends FilesDb {

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
   * Set while resolve() is inside the legacy layers after a failed probe, so
   * the index is not asked a second time for the same name.
   *
   * @var bool
   */
  protected $skip_index = FALSE;

  /* ── Availability ──────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   */
  public function available() {
    if ($this->ext_root === NULL) {
      // No autoload: an extension registers its classes at startup, so if
      // QuantaDb is not here already it is not coming — and asking the
      // autoloader for a class with no namespace is what made it warn.
      //
      // method_exists() on load(): a stale .so predating it would make every
      // call fall through, and this class must not claim an API it half has.
      $this->ext_root = (class_exists('QuantaDb', FALSE) && method_exists('QuantaDb', 'load'))
        ? rtrim((string) (ini_get('quanta_db.root') ?: getenv('QUANTA_DB_ROOT')), '/')
        : '';
    }
    return $this->ext_root !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function coherent() {
    if ($this->is_coherent === NULL) {
      try {
        $this->is_coherent = $this->available() && \QuantaDb::coherent();
      }
      catch (\Throwable $e) {
        $this->fail($e);
        $this->is_coherent = FALSE;
      }
    }
    return $this->is_coherent;
  }

  /**
   * {@inheritdoc}
   */
  public function version() {
    if (!$this->available()) {
      return parent::version();
    }
    try {
      return \QuantaDb::version();
    }
    catch (\Throwable $e) {
      $this->fail($e);
      return parent::version();
    }
  }

  /* ── Resolving a name ──────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * When the index is coherent it is asked FIRST and the tmp/cache/a/b/c shard
   * symlink is skipped entirely. That symlink layer exists to avoid exec(find)
   * — but the extension already avoids it, answering from a hash probe with no
   * syscall. In front of such a lookup the symlink is strictly negative: a
   * readlink() on every cold name (plus, when the shard has no entry yet, a
   * symlink()+rename() writing a cache nothing will read) guarding a lookup
   * that needs neither.
   *
   * $link searches are excluded on purpose: their callers readlink() the
   * result themselves, so they keep the legacy find, which — unlike the index —
   * also accepts symlink candidates.
   */
  protected function resolve($name, $opts = array()) {
    if (!empty($opts['link']) || !$this->coherent()) {
      return parent::resolve($name, $opts);
    }

    $path = $this->indexPath($name);
    // No is_dir() on this answer. Coherent means the index is authoritative for
    // existence, so the stat would only re-ask the kernel what the index just
    // said. That is NOT true of the shard symlink parent::resolve() reads: that
    // one is a cache written by an earlier request and can still point at a
    // directory that has since been deleted, so the is_dir() guarding it is
    // load-bearing and stays where it is.
    //
    // The empty-string test is not paranoia about the extension so much as
    // fidelity: the legacy layers test a resolved path with a loose == FALSE,
    // which treats '' as "not resolved".
    if (is_string($path) && $path !== '') {
      return $this->remember($name, $path);
    }
    if ($path === FALSE) {
      return $this->markMissing($name);
    }

    // NULL while coherent() is TRUE means the probe threw — an unavailable
    // extension cannot be coherent. Take the legacy layers, but do not ask a
    // second time.
    $this->skip_index = TRUE;
    try {
      return parent::resolve($name, $opts);
    }
    finally {
      $this->skip_index = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Three-state, and the caller (resolve()) needs all three: a path; FALSE for
   * "definitively absent", which only a coherent index can say; NULL for "no
   * answer", which sends the resolution on to the shard symlink and the search.
   */
  protected function indexPath($name) {
    if ($this->skip_index || !$this->available()) {
      return NULL;
    }
    // coherent() before the lookup: it decides what a miss means, and resolving
    // it first keeps that decision out of the per-lookup path.
    $coherent = $this->coherent();
    try {
      $path = \QuantaDb::path($name);
    }
    catch (\Throwable $e) {
      $this->fail($e);
      return NULL;
    }
    if ($path === NULL) {
      return $coherent ? FALSE : NULL;
    }
    return $this->toDocroot($path);
  }

  /**
   * Whether the index holds $name, and holds it at the directory the caller
   * named.
   *
   * This is the check the wired call sites used to make inline with
   * resolvesTo(), moved in. It deliberately asks the INDEX rather than
   * $this->path(): the question is "can the extension serve this call", and a
   * name the index does not know is a no regardless of what an exec(find)
   * would eventually turn up.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   The caller's options; 'at' is the directory to agree about.
   *
   * @return bool
   */
  protected function serves($name, $opts = array()) {
    if (!$this->available() || empty($name)) {
      return FALSE;
    }
    if (!isset($opts['at']) || !is_string($opts['at']) || $opts['at'] === '') {
      // Nothing to check. Do NOT probe just to find out whether the index knows
      // the name — the call that follows asks the same question and answers it,
      // and paying for both would put a second probe on every document read.
      // That is the tax simpler-faster.md §4.1 removed; it is not coming back.
      return TRUE;
    }
    $indexed = $this->indexPath($name);
    if (!is_string($indexed) || $indexed === '') {
      return FALSE;
    }
    // String compare first: the path almost always came from path() and is
    // already identical, so realpath() (two syscall-heavy resolutions) is only
    // the tie-breaker.
    return $indexed === $opts['at'] || realpath($indexed) === realpath($opts['at']);
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

  /**
   * Map a path under the current host's docroot onto the extension's root.
   *
   * The inverse of toDocroot(), for the arguments that travel the other way.
   * The extension compares paths against its OWN root, so a caller's docroot
   * spelling has to be translated before it is sent, not after it comes back —
   * an untranslated one would fail every identity check on a sites/<alias>
   * host and quietly send that host's whole read path down the legacy branch.
   *
   * @param string $path
   *   A path under the current host's docroot.
   *
   * @return string
   *   The path as the extension names it.
   */
  protected function fromDocroot($path) {
    $docroot = $this->env->dir['docroot'];
    if ($docroot !== $this->ext_root && strpos($path, $docroot . '/') === 0) {
      return $this->ext_root . substr($path, strlen($docroot));
    }
    return $path;
  }

  /* ── Document reads ────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   */
  public function data($name, $lang = NULL, $opts = array()) {
    if ($this->serves($name, $opts)) {
      try {
        return \QuantaDb::get($name, $lang);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::data($name, $lang, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function object($name, $lang = NULL, $opts = array()) {
    if ($this->serves($name, $opts)) {
      try {
        return \QuantaDb::getObject($name, $lang);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::object($name, $lang, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function raw($name, $lang = NULL, $opts = array()) {
    if ($this->serves($name, $opts)) {
      try {
        return \QuantaDb::getRaw($name, $lang);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::raw($name, $lang, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * One call, one probe. This used to be three at the call site — a path
   * lookup for the node's absolute path, then getObject($language), then
   * getObject(NULL) on a miss. All three interrogated the same index
   * record, and the first one's answer existed only to be compared once and
   * dropped. 'at' carries the comparison and 'fallback' the language order into
   * the probe that already holds the record, so no path is built at all.
   *
   * Measured against a live daemon (files-db/tests/bench/probe.php, php:8.2-fpm):
   *
   *   document      legacy fgc+json_decode   QuantaDb::getObject()
   *   48 B                       6.53 us                 0.24 us    27x
   *   437 B                      7.19 us                 0.44 us    16x
   *   205 KB                   129.61 us                 0.46 us   279x
   *
   * Most of the legacy cost is the syscalls (~6.3 us of the 6.53 us on a tiny
   * document); the rest is json_decode, which the daemon's pre-decoded document
   * image removes entirely. The 205 KB row is flat because the extension points
   * PHP's string zvals straight at the shared mapping instead of copying the
   * body (files-db/README.md, "Document reads").
   *
   * Re-measure before changing this: Node::loadJSON is the hottest function on
   * admin list pages (~26% of render self-time).
   */
  public function load($name, $opts = array()) {
    if (!$this->available()) {
      return parent::load($name, $opts);
    }
    $ext_opts = $opts;
    $at = NULL;
    if (isset($ext_opts['at']) && is_string($ext_opts['at']) && $ext_opts['at'] !== '') {
      $at = $ext_opts['at'];
      $ext_opts['at'] = $this->fromDocroot($at);
    }
    try {
      $loaded = \QuantaDb::load($name, $ext_opts);
    }
    catch (\Throwable $e) {
      // A torn or invalid document arrives as CORRUPT_JSON. Do NOT hand it to
      // the filesystem read hoping for better — parent::load() would throw the
      // same thing off the same bytes. Re-throw it as ours so the one caller
      // that distinguishes "no document" from "unreadable document"
      // (Node::loadJSON) sees the same exception in both implementations.
      throw $this->wrap($e);
    }
    if (is_array($loaded)) {
      if (isset($loaded['path'])) {
        $loaded['path'] = $this->toDocroot($loaded['path']);
      }
      return $loaded;
    }
    // NULL collapses three answers and only one of them means "this node has no
    // document": the name really does resolve to 'at' and has nothing to read.
    // The other two — the name does not resolve at all, or it resolves to a
    // different directory — must reach the filesystem read, which reads the
    // directory the caller named rather than wherever the name points. serves()
    // separates them, and it is paid only here, on the path where there was no
    // document to return anyway; the hot path returned one probe ago.
    if ($this->coherent() && $this->serves($name, $opts)) {
      return NULL;
    }
    return parent::load($name, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function langs($name, $opts = array()) {
    if ($this->serves($name, $opts)) {
      try {
        $meta = \QuantaDb::meta($name);
        if (is_array($meta) && isset($meta['langs'])) {
          return $meta['langs'];
        }
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::langs($name, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * NodeFactory::load() asks this for every node it loads, so the stat it
   * replaces is on the hottest path in the system.
   */
  public function hasLang($name, $lang, $opts = array()) {
    // meta()['langs'] reports the neutral document as '', which is also what an
    // empty $lang means here, so the two agree — but only because docName()
    // makes both implementations name the same file for an empty language.
    if ($this->serves($name, $opts)) {
      try {
        $meta = \QuantaDb::meta($name);
        if (is_array($meta) && isset($meta['langs'])) {
          return in_array((string) $lang, $meta['langs'], TRUE);
        }
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::hasLang($name, $lang, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * 'generation' and 'containers' come back here and not from FilesDb — see
   * that method for why asking the filesystem for containers per node would be
   * a disaster for the caller that does it per page.
   */
  public function meta($name, $opts = array()) {
    if ($this->serves($name, $opts)) {
      try {
        $meta = \QuantaDb::meta($name);
        if (is_array($meta)) {
          if (isset($meta['path'])) {
            $meta['path'] = $this->toDocroot($meta['path']);
          }
          return $meta;
        }
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::meta($name, $opts);
  }

  /* ── Structure ─────────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * The index only answers about NODES, so it can serve this question only when
   * the caller is asking about nodes:
   *
   *   - DIR_DIRS + 'symlinks' => 'no'   → real child directories
   *   - DIR_DIRS or DIR_ALL, 'only'     → symlinked members
   *   - DIR_DIRS                        → both, which is what scanDirectory
   *                                       returns for it
   *
   * DIR_ALL and DIR_FILES also return the plain files sitting in the node
   * directory (tpl.html, data.json, uploads), which are deliberately not
   * indexed, so they stay on the scan. So does any exclude_dirs other than
   * Quanta's '_' convention, which the index has no way to express.
   *
   * Note 'symlinks' alone is NOT enough to make the question node-only: with
   * the default DIR_ALL, 'no' means "everything that is not a symlink", which
   * includes data.json and every upload. Only 'only' is safe on its own, since
   * a plain file is never a symlinked member. The parity suite pins this
   * (files-db/tests/quanta/01_shim_reads.php).
   */
  public function children($father, $attributes = array()) {
    $type = isset($attributes['type']) ? $attributes['type'] : Environment::DIR_ALL;
    $symlinks = isset($attributes['symlinks']) ? $attributes['symlinks'] : NULL;
    $exclude = array_key_exists('exclude_dirs', $attributes)
      ? $attributes['exclude_dirs']
      : Environment::DIR_INACTIVE;

    $ext_type = NULL;
    if ($symlinks === 'no' && $type === Environment::DIR_DIRS) {
      $ext_type = 'dirs';
    }
    elseif ($symlinks === 'only' && $type !== Environment::DIR_FILES) {
      $ext_type = 'links';
    }
    elseif ($symlinks === NULL && $type === Environment::DIR_DIRS) {
      $ext_type = 'all';
    }

    $expressible = ($ext_type !== NULL)
      && ($exclude === Environment::DIR_INACTIVE || empty($exclude));

    if ($expressible && $this->serves($father, $attributes)) {
      try {
        return \QuantaDb::children($father, array(
          'type' => $ext_type,
          'include_hidden' => empty($exclude),
        ));
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::children($father, $attributes);
  }

  /**
   * {@inheritdoc}
   */
  public function child($father, $name, $opts = array()) {
    if (empty($name)) {
      return FALSE;
    }
    // exclude_dirs '': '_'-prefixed children stay in the answer, because the
    // is_dir() this replaces never hid them and callers ask about names like
    // '_jobs_todo'.
    if ($this->serves($father, $opts)) {
      try {
        return in_array($name, \QuantaDb::children($father, array(
          'type' => 'all',
          'include_hidden' => TRUE,
        )), TRUE);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::child($father, $name, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * Unscoped, this is exactly links() (api-contract.md §9). Scoping the search
   * to one subtree is not something the contract can express, so an 'in' goes
   * to the filesystem sweep.
   */
  public function links($target, $opts = array()) {
    if (!isset($opts['in']) && $this->serves($target, $opts)) {
      try {
        $containers = \QuantaDb::links($target);
        if (is_array($containers)) {
          return $containers;
        }
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::links($target, $opts);
  }

  /* ── Queries ───────────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * An empty array from the extension is an answer, not a miss: even in
   * fallback mode it comes off a real filesystem walk. Only a failure falls
   * through — otherwise every query that legitimately matched nothing would pay
   * for a second, slower search of the same tree.
   */
  public function find($criteria, $opts = array()) {
    if ($this->available()) {
      try {
        return \QuantaDb::find($criteria, $opts);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::find($criteria, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function count($criteria) {
    if ($this->available()) {
      try {
        return \QuantaDb::count($criteria);
      }
      catch (\Throwable $e) {
        $this->fail($e);
      }
    }
    return parent::count($criteria);
  }

  /* ── Writes ────────────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * With 'father' this is a create, and the contract reserves the directory
   * atomically — raising EXISTS when the name is already taken anywhere in the
   * tree, which FilesDb cannot check without an exec(find) per creation. A
   * caller that wants that guarantee gets it here and not there; see the
   * contract's "Adopting the write API".
   */
  public function put($name, array $data, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        return \QuantaDb::put($name, $data, $this->putOpts($opts));
      }
      catch (\Throwable $e) {
        $e = $this->wrap($e);
        if (!$this->ignorableExists($e, $opts)) {
          throw $e;
        }
      }
    }
    return parent::put($name, $data, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function putRaw($name, $json, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        return \QuantaDb::putRaw($name, $json, $this->putOpts($opts));
      }
      catch (\Throwable $e) {
        $e = $this->wrap($e);
        if (!$this->ignorableExists($e, $opts)) {
          throw $e;
        }
      }
    }
    return parent::putRaw($name, $json, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * Here the read and the write happen under the node's lock, which is the one
   * guarantee FilesDb cannot make.
   */
  public function update($name, $fn, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        return \QuantaDb::update($name, $fn, $this->putOpts($opts));
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::update($name, $fn, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDoc($name, $lang = NULL, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        return \QuantaDb::deleteDoc($name, $lang);
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::deleteDoc($name, $lang, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * Every inbound symlink is re-pointed, which a bare rename() does not do —
   * it leaves every container membership dangling. That is the difference
   * between this and FilesDb::move(), and it is why Doctor::checkBrokenLinks
   * exists for the other one.
   */
  public function move($name, $new_father = NULL, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        $moved = \QuantaDb::move($name, $new_father, $this->extOpts($opts));
        $this->forget($name);
        if (isset($opts['name'])) {
          $this->forget($opts['name']);
        }
        return $moved;
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::move($name, $new_father, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * The move happens under the node's lock and the index learns of it on the
   * ack, rather than an unlocked `mv` the watcher notices some time later.
   * Both implementations land the node in the same trashbin root —
   * quanta_db.trashbin_dir is pointed at $env->dir['trashbin'] — so recovery
   * works the way it always did.
   */
  public function delete($name, $opts = array()) {
    if ($this->writable($name, $opts)) {
      try {
        $deleted = \QuantaDb::delete($name);
        $this->forget($name);
        return $deleted;
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::delete($name, $opts);
  }

  /* ── Container membership ──────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * A link is always named after its target in the contract, so a custom
   * 'name' goes to the filesystem.
   */
  public function link($target, $container, $opts = array()) {
    $named = isset($opts['name']) ? $opts['name'] : $target;
    if ($named === $target
        && is_string($this->indexPath($target))
        && is_string($this->indexPath($container))) {
      $if_exists = isset($opts['if_exists']) ? $opts['if_exists'] : 'error';
      try {
        if ($if_exists === 'override') {
          // "Make this link exist and point at the source", which is how
          // Doctor::checkBrokenLinks repairs a dangling one. It has to be a
          // real unlink + link: if_exists => 'ignore' would see the broken
          // entry, call it present and leave it broken.
          \QuantaDb::unlink($target, $container, array('if_not_exists' => 'ignore'));
          $if_exists = 'ignore';
        }
        return \QuantaDb::link($target, $container, array('if_exists' => $if_exists));
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::link($target, $container, $opts);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($target, $container, $opts = array()) {
    $named = isset($opts['name']) ? $opts['name'] : $target;
    if ($named === $target && is_string($this->indexPath($container))) {
      try {
        return \QuantaDb::unlink($target, $container, array(
          'if_not_exists' => isset($opts['if_not_exists']) ? $opts['if_not_exists'] : 'error',
        ));
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::unlink($target, $container, $opts);
  }

  /**
   * {@inheritdoc}
   *
   * Under the node's lock, so a reader never sees it in zero or two containers
   * — which the unlink-then-link in FilesDb cannot promise.
   */
  public function relink($target, $from_container, $to_container, $opts = array()) {
    if (is_string($this->indexPath($target))
        && is_string($this->indexPath($from_container))
        && is_string($this->indexPath($to_container))) {
      try {
        return \QuantaDb::relink($target, $from_container, $to_container);
      }
      catch (\Throwable $e) {
        throw $this->wrap($e);
      }
    }
    return parent::relink($target, $from_container, $to_container, $opts);
  }

  /* ── Maintenance ───────────────────────────────────────────────────── */

  /**
   * {@inheritdoc}
   *
   * Safe at any time, including under traffic. Call it after something has
   * rewritten the tree from outside PHP (a restore, an rsync), and to repair
   * dangling symlinks left by a crash in the middle of a move().
   */
  public function reindex($subtree = NULL) {
    if (!$this->available()) {
      return parent::reindex($subtree);
    }
    try {
      $rebuilt = \QuantaDb::reindex($subtree);
      $this->forget();
      return $rebuilt;
    }
    catch (\Throwable $e) {
      $this->fail($e);
      return parent::reindex($subtree);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stats() {
    if (!$this->available()) {
      return parent::stats();
    }
    try {
      return \QuantaDb::stats();
    }
    catch (\Throwable $e) {
      $this->fail($e);
      return parent::stats();
    }
  }

  /* ── Plumbing ──────────────────────────────────────────────────────── */

  /**
   * Whether a write can go to the extension.
   *
   * Stricter than serves(), and deliberately so. serves() waves a name through
   * when there is no 'at' to check, because the read that follows asks the same
   * question and answers it — a second probe there would be pure tax on the hot
   * path. A write has no such luxury: the extension's answer to a node it does
   * not hold is an exception, and this class does not retry a failed write
   * against the filesystem. So the node is confirmed first. Writes are rare;
   * one probe is nothing.
   *
   * A create ('father' given) is addressed by its FATHER — asking about a name
   * that does not exist yet would always say no and send every creation to the
   * filesystem, losing the atomic name reservation that is the whole point of
   * declaring create intent.
   *
   * @param string $name
   *   The node name.
   * @param array $opts
   *   The caller's options.
   *
   * @return bool
   */
  protected function writable($name, $opts) {
    if (!empty($opts['father'])) {
      if (!is_string($this->indexPath($opts['father']))) {
        return FALSE;
      }
      // 'at', when given, is where the caller intends the node to land. It has
      // to be father/name, or the two implementations would create it in
      // different places.
      if (isset($opts['at']) && is_string($opts['at']) && $opts['at'] !== '') {
        return basename($opts['at']) === $name
          && $this->serves($opts['father'], array('at' => dirname($opts['at'])));
      }
      return TRUE;
    }
    if (isset($opts['at']) && is_string($opts['at']) && $opts['at'] !== '') {
      return $this->serves($name, $opts);
    }
    return is_string($this->indexPath($name));
  }

  /**
   * Whether a failed create should be retried against the filesystem.
   *
   * The contract reserves a node name tree-wide, so creating one that is
   * already in use raises EXISTS. A caller passing if_exists => 'ignore' has
   * said it wants the directory anyway — the historical Quanta behaviour, where
   * a duplicate name simply produced a second directory and the resolver warned
   * about it later. Only FilesDb can do that, so the call goes there.
   *
   * Nothing else falls back: a locked, half-completed write is not something to
   * paper over by doing it again unlocked.
   *
   * @param FilesDbException $e
   *   The wrapped failure.
   * @param array $opts
   *   The caller's options.
   *
   * @return bool
   */
  protected function ignorableExists(FilesDbException $e, $opts) {
    return $e->getCode() == FilesDbException::EXISTS
      && !empty($opts['father'])
      && isset($opts['if_exists'])
      && $opts['if_exists'] === 'ignore';
  }

  /**
   * Strip this class's options before they reach the extension.
   *
   * 'at' is ours: the contract has it on load() alone, and everywhere else it
   * has already done its job in serves()/writable().
   *
   * @param array $opts
   *   The caller's options.
   *
   * @return array
   *   The options the contract defines.
   */
  protected function extOpts($opts) {
    unset($opts['at']);
    return $opts;
  }

  /**
   * extOpts() for put()/putRaw(), which also own 'if_exists'.
   *
   * move() takes an 'if_exists' the contract defines ('error' | 'replace'), so
   * it cannot be stripped there — but put()'s is ours, and passing it on would
   * be an unknown option to the extension.
   *
   * @param array $opts
   *   The caller's options.
   *
   * @return array
   *   The options the contract defines.
   */
  protected function putOpts($opts) {
    unset($opts['if_exists']);
    return $this->extOpts($opts);
  }

  /**
   * Record a swallowed failure.
   *
   * @param \Throwable $e
   *   The exception.
   */
  protected function fail(\Throwable $e) {
    $this->last_error = $e;
  }

  /**
   * Re-throw an extension failure as ours, code preserved.
   *
   * @param \Throwable $e
   *   Anything the extension threw — a \QuantaDbException, or an \Error from a
   *   stale .so that is missing the method.
   *
   * @return FilesDbException
   *   The exception to throw.
   */
  protected function wrap(\Throwable $e) {
    $this->fail($e);
    if ($e instanceof FilesDbException) {
      return $e;
    }
    return new FilesDbException($e->getMessage(), (int) $e->getCode(), $e);
  }

}
