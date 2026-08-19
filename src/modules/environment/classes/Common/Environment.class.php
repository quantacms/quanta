<?php
namespace Quanta\Common;

/**
 * Class Environment
 * This class represents an Environment with his directories etcetera.
 */
class Environment extends DataContainer {
  const DIR_INACTIVE = '_';
  const DIR_ALL = 'all';
  const DIR_DIRS = 'dirs';
  const DIR_FILES = 'files';
  const DIR_MODULES = 'modules';
  const DIR_TPL = 'tpl';
  const QUANTA_ROOT = '__ROOT__';
  const GLOBAL_SEPARATOR = '@\@/@\@/@\@/@';

  public $host = array();
  public $dir = array();
  public $request_uri;
  public $site_url;
  public $query_params;
  public $request_path;
  public $request_json;
  public $request = array();
  public $class_map = array();
  private $modules = array();
  // TODO: maybe we just need modules. Created this var to rearrange them in right order taking care of dependencies.
  private $modules_loaded = array();
  private $includes = array();
  private $context;
  // Per hook-name cache of the module functions that implement it. See hook().
  private $hook_implementations = array();
  // The node database accessor. See db().
  private $files_db = NULL;

  /**
   * The node database.
   *
   * Every access to the file-based node tree goes through here, and this is
   * the one place that knows there are two implementations of it: FilesDbExt
   * when the quanta_db extension is loaded, FilesDb — the filesystem, and a
   * complete implementation on its own — when it is not. Both answer every
   * method, so no caller branches on which one it got.
   *
   * The choice is NOT made by the autoloader, deliberately. The class map is
   * rebuilt only when the file is missing (boot.php), so a map baked while the
   * extension was loaded would keep naming the wrong class after it was turned
   * off, with nothing to invalidate it; and mapClasses() keys the map by
   * filename, which two files declaring one class name cannot share. This is
   * one `if` in the method that is already the single entry point.
   *
   * @return FilesDb
   *   The accessor for this Environment.
   */
  public function db() {
    if ($this->files_db === NULL) {
      // The autoloader reads a class map that is only rebuilt when it is
      // missing (boot.php), so a deploy that adds these classes to an existing
      // site would not find them until the cache is cleared. Load directly.
      if (!class_exists('\Quanta\Common\FilesDb', FALSE)) {
        require_once __DIR__ . '/FilesDb.class.php';
      }
      // No autoload for QuantaDb: an extension registers its classes at
      // startup, so if it is not here already it is not coming — and asking the
      // autoloader for a class with no namespace is what made it warn.
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

  /**
   * Environment constructor.
   *
   * @param string $host
   *   The host the Environment is running into.
   *
   * @param string $request_uri
   *   The current request URI.
   *
   * @param string $docroot
   *   The current document root.
   */
  public function __construct($host = NULL, $request_uri = NULL, $docroot = NULL) {
    if (empty($host)) {
      $full_host = strtolower($_SERVER['HTTP_HOST']);
      $host = explode(':', $full_host)[0];
    }
    $this->host = $host;
    if ($request_uri == NULL && !empty($_SERVER['REQUEST_URI'])) {
      // Remove querystring to obtain request uri...
      $exp = explode('?', $_SERVER['REQUEST_URI']);
      $this->request_uri = (str_replace('/', '', $exp[0]) == '') ? '/home/' : $exp[0];
       // Check if there is a query string
      if (isset($exp[1])) {
        // Parse the query string into an associative array
        // Now query_params is an array with key => value pairs from the query string
        parse_str($exp[1], $this->query_params);
      } else {
        // No query string present
        $this->query_params = [];
      }
    }
    else {
      $this->request_uri = $request_uri;
    }
    if ($this->request_uri != NULL) {
      $this->request = explode('/', $this->request_uri);
      if (empty($this->request[count($this->request) - 1])) {
        unset ($this->request[count($this->request) - 1]);
      }
      $this->request_path = $this->request[count($this->request) - 1];
    }

    if ($docroot == NULL) {
      $docroot = $_SERVER['DOCUMENT_ROOT'];
    }
    $this->site_url =  $this->getProtocol() . '://' . $this->host;
    // TODO: move request_uri in data.
    $this->setData('request_url', $this->site_url . $this->request_uri);
    $this->dir['quanta'] = $docroot;
    $this->dir['sites'] = $this->dir['quanta'] . '/sites';
    $this->dir['src'] = $this->dir['quanta'] . '/src';
    $this->dir['profiles'] = $this->dir['quanta'] . '/profiles';

    $this->dir['docroot'] = $this->dir['sites'] . '/' . $this->host;
    $this->dir['static'] = $this->dir['quanta'] . '/static';
    $this->dir['tmp_global'] = $this->dir['static'] . '/tmp';
    $this->dir['tmp'] = $this->dir['tmp_global'] . '/' . $this->host;
    $this->dir['trashbin'] = $this->dir['tmp'] . '/trashbin';
    $this->dir['vendor'] = $this->dir['quanta'] . '/vendor';
    $this->dir['modules_core'] = $this->dir['src'] . '/modules';
    $this->dir['modules_custom'] = $this->dir['docroot'] . '/_modules';
    $this->dir['db'] = $this->dir['docroot'] . '/db';
    $this->dir['users'] = $this->dir['docroot'] . '/db/_users';
    $this->dir['tpl'] = $this->dir['docroot'] . '/_tpl';
	
    // TODO: move to files module.
    $this->dir['tmp_files'] = $this->dir['tmp'] . '/files';
    $this->dir['log'] = $this->dir['tmp'] . '/log';
    // TODO: generation to be done in user and node modules.
    $this->dir['statuses'] = $this->dir['docroot'] . '/db/_statuses';

    if (isset($_REQUEST['json'])) {
      $this->request_json = json_decode($_REQUEST['json']);
    }
    $this->setData('timestamp', time());
  }

  /**
   * Get the current server protocol.
   */
  public function getProtocol() {
    if (isset($_SERVER['HTTPS']) &&
      ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
      isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
      $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
      $protocol = 'https';
    }
    else {
      $protocol = 'http';
    }

    return $protocol;
  }

  /**
   * Set the content for the shadow.
   * @param $context
   */
  public function setContext($context) {
    $this->context = $context;
  }

  /**
   * Get the context of the shadow.
   * @return mixed
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * Get the context of the shadow.
   * @return mixed
   */
  public function getBaseUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && !in_array(strtolower($_SERVER['HTTPS']), array('off', 'no')) ? 'https' : 'http') . '://' . $this->host;
  }

  /**
   * Returns the current requested path.
   *
   * @return string mixed
   */
  public function getRequestedPath() {
    return $this->request_path;
  }

  /**
   * Load modules (core and custom).
   *
   * @param $dir
   *   The directory into which to look for modules.
   *
   * @param $mod_type
   *   The type of modules being loaded (can be core or custom).
   */
  private function loadModules($dir, $mod_type) {
    $modules = $this->scanDirectory($dir, array('type' => self::DIR_MODULES));
    $this->setModules($mod_type, $modules);
  }

  /**
   * Setup modules into the environment.
   *
   * @param $mod_type
   *   The type of modules being loaded (can be core or custom).
   *
   * @param $modules
   *   The modules to load into the environment.
   */
  public function setModules($mod_type, $modules) {

    $module_path = $mod_type == 'core' ? $this->dir['modules_core'] : $this->dir['modules_custom'];

    foreach ($modules as $k => $module) {
      $this->modules[$module] = array(
        'name' => $module,
        'path' => $module_path . '/' . $module,
      );
    }
  }

  /**
   * Get a loaded module.
   *
   * @param $module
   *   The name of the module to retrieve.
   *
   * @return mixed
   *   The module.
   */
  public function getModule($module) {
    return $this->modules[$module];
  }

  /**
   * Get a loaded module's path.
   *
   * @param $module
   *   The module for which to look for the path.
   *
   * @return mixed
   *   The module path.
   */
  public function getModulePath($module) {
    return $this->modules[$module]['path'];
  }

  /**
 * Get all existing modules.
 *
 * @return array
 *   The existing modules.
 */
  public function getModules() {
    return $this->modules;
  }

  /**
   * Get all the modules already loaded in the environment.
   *
   * @return array
   *   The modules already loaded in the environment.
   */
  public function getLoadedModules($priority = null) {
    $modules_loaded = $this->modules_loaded;
    if($priority){
      if(isset($modules_loaded[$priority])){
        $priority_module = $modules_loaded[$priority];
        // Remove priority module from its original position
        unset($modules_loaded[$priority]);
        // Add priority module to the beginning of the array
        $modules_loaded = array($priority => $priority_module) + $modules_loaded;
      }
    }
    return $modules_loaded;
  }

  /**
   * Run all loaded modules.
   */
  public function mapClasses() {
    // TODO: this is needed when the class map is not created yet (i.e. at very first install).
    if (!is_dir($this->dir['tmp'])) {
      mkdir($this->dir['tmp']);
    }
    $fop = fopen(CLASS_MAP_FILE, 'w+');

    foreach ($this->modules_loaded as $module) {
      $autoload_paths = array('Common', 'Qtags');
      foreach ($autoload_paths as $autoload_path) {
        $full_autoload_path = $module['path'] . '/classes/' . $autoload_path;
        /**
         * Autoload module's qtags.
         */
        if (is_dir($full_autoload_path)) {
          $classes = $this->scanDirectory($full_autoload_path);
          foreach ($classes as $class) {
            // Parse the Qtag.
            $exp1 = explode('/', $class);
            $exp2 = explode('.', $exp1[count($exp1) - 1]);
            $item_name = $exp2[0];
            $this->class_map[$autoload_path][$item_name] = $full_autoload_path . '/' . $class;
          }
        }
      }
    }
    fwrite($fop, serialize($this->class_map));
    fclose($fop);
  }

  /**
   * Run all loaded modules.
   */
  public function runModules() {
    foreach ($this->modules as $module) {
      $this->runModule($module);
    }
  }

  /**
   * Sets up a dependency between two modules.
   *
   * @param $module
   *   The module to run before the depending one.
   */
  public function dependsFrom($module) {
    $this->runModule($this->getModule($module));
  }

  /**
   * Run (Load) a module.
   * Run all Quanta autoload routines on each module.
   *
   * @param $module
   */
  public function runModule($module) {
    $this->modules_loaded[$module['name']] = $module;
    // TODO: deprecate procedural hooks. Find an efficient OO approach.
    $includes = array('hook');
    foreach ($includes as $include_type) {
      $include_path = $module['path'] . '/hooks/' . $module['name'] . '.' . $include_type . '.inc';

      if (is_file($include_path)) {
        require_once($include_path);
      }
    }
  }

  /**
   * Get all dirs inside a given dir.
   * @param string $base_dir
   * @param array $attributes
   * @return array
   */
  public function scanDirectory($base_dir = '', $attributes = array()) {
    if (!is_dir($base_dir)) {
      return array();
    }
    if (!isset($attributes['exclude_dirs'])) {
      $attributes['exclude_dirs'] = self::DIR_INACTIVE;
    }
    if (!isset($attributes['type'])) {
      $attributes['type'] = self::DIR_ALL;
    }

    $dirs = array_diff(scandir($base_dir), array('.', '..', '.git', 'assets', 'files'));

    foreach ($dirs as $k => $dir) {
      // Remove inactive if requested.
      if (substr($dir, 0, 1) == $attributes['exclude_dirs']) {
        unset ($dirs[$k]);
      }

      if (isset($attributes['symlinks']) && $attributes['symlinks'] == 'no' && is_link($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }
      else if (isset($attributes['symlinks']) && $attributes['symlinks'] == 'only' && !is_link($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }

      if ($attributes['type'] == self::DIR_DIRS && !is_dir($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }
      elseif ($attributes['type'] == self::DIR_FILES && !is_file($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }
      elseif ($attributes['type'] == self::DIR_MODULES &&
        (!is_dir($base_dir . '/' . $dir))
      ) {
        unset ($dirs[$k]);
      }
    }

    return $dirs;
  }

  /**
   * List a NODE's children, by name.
   *
   * scanDirectory() takes a path and reads the filesystem. When the caller
   * knows the node NAME that path belongs to, the same question goes to the
   * node database instead, which answers it from the index when it can and
   * from this very scan when it cannot (see FilesDbExt::children() for which
   * attribute combinations are expressible).
   *
   * 'at' carries the path in with the name. Names are the database's key, but
   * a caller can hold a path that was never resolved from one
   * (NodeFactory::loadFromRealPath), and must not be told about a different
   * directory that happens to share the basename — 'at' is what settles that,
   * inside the probe rather than in a resolvesTo() call in front of it.
   *
   * @param string $path
   *   The directory to list.
   * @param string|null $name
   *   The node name that directory belongs to, if known.
   * @param array $attributes
   *   scanDirectory() attributes.
   *
   * @return array
   *   Child names.
   */
  public function scanNodeDirectory($path, $name, $attributes = array()) {
    if (empty($name)) {
      // array_values(): scanDirectory() leaves holes in the keys where it
      // unset() entries, while children() returns a list. Both paths out of
      // this method must be the same shape or a caller's ===, [0] or
      // json_encode() would depend on which one answered.
      return array_values($this->scanDirectory($path, $attributes));
    }
    return $this->db()->children($name, $attributes + array('at' => $path));
  }

  /**
   * Get all dirs inside a given dir, at a leaf level.
   *
   * @param $base_dir
   * @param $dir
   * @param array $dirs
   * @param array $attributes
   * @param int $depth
   * @return array
   */
  public function scanDirectoryDeep($base_dir, $dir, $dirs = array(), $attributes = array('exclude_dirs' => self::DIR_INACTIVE, 'type' => self::DIR_ALL, 'level' => 'leaf'), $depth = 0) {
    // $dir is the node name at every level below the first: the recursion
    // below passes the child's name down as $dir. At depth 0 it is '' (the
    // caller only has a path), so the root level alone stays on the scan.
    $scan = ($this->scanNodeDirectory($base_dir . '/' . $dir, $dir, $attributes));

    $item = array(
      'path' => $base_dir . '/' . $dir,
      'name' => $dir,
      'depth' => $depth,
    );
    if (count($scan) == 0 || ($depth != 0 && is_link($base_dir))) {
      $dirs[] = $item;
    }
    else {
      $i = 0;
      if ($attributes['level'] == 'tree') {
        $dirs[] = $item;
      }
      $depth++;
      foreach ($scan as $scandir) {

        $next_dir = ($base_dir . '/' . $dir . '/' . $scandir);
        if (is_dir($next_dir)) {
          $dirs = $this->scanDirectoryDeep($base_dir . '/' . $dir, $scandir, $dirs, $attributes, $depth);
          $i++;
        }
      }
      // If $i didn't grow, means the directory contains only files, so it's a leaf.
      if ($i == 0 && $attributes['level'] == 'leaf') {
        $dirs[] = $item;
      }
    }
    return $dirs;
  }

  /**
   * Load and run system modules, core and custom.
   */
  public function load() {
    $this->loadModules($this->dir['modules_core'], 'core');
    $this->loadModules($this->dir['modules_custom'], 'custom');
    $this->runModules();
  }

  /**
   * Hook function - will look for all modulename_function in all active modules
   * and let the user alter the variables contained into &$vars.
   *
   * @param string $function
   *   The hook function name.
   *
   * @param array $vars
   *   An array of variables.
   *
   * @return bool
   *   Returns TRUE if any module was implementing the hook.
   */
  public function hook($function, array &$vars = array()) {
    $env = &$this;
    // Resolve the implementing module functions once per hook name. The loaded
    // modules and their procedural hooks are fixed after load() (which
    // require_once's every <module>/hooks/<module>.hook.inc via runModules),
    // and all hooks fire after that — so instead of rebuilding the function
    // name and calling function_exists() across all ~44 modules on every call
    // (this method tops the profiler), we cache the resolved implementer list.
    if (!isset($this->hook_implementations[$function])) {
      $impls = array();
      foreach ($this->getLoadedModules('environment') as $module) {
        $hook = __NAMESPACE__ . '\\' . $module['name'] . '_' . $function;
        if (function_exists($hook)) {
          $impls[] = $hook;
        }
      }
      $this->hook_implementations[$function] = $impls;
    }
    $hooked = FALSE;
    foreach ($this->hook_implementations[$function] as $hook) {
      $hook($env, $vars);
      $hooked = TRUE;
    }
    return $hooked;
  }


  /**
   * Start client session.
   */
  public function startSession() {
    $protocol = $this->getProtocol();
    $secure = ($protocol === 'https');

    if (PHP_VERSION_ID >= 70300) {
      session_set_cookie_params([
        'samesite' => $secure ? 'None' : 'Lax', // None requires Secure=true
        'secure' => $secure,
      ]);
    } else {
      ini_set('session.cookie_samesite', $secure ? 'None' : 'Lax');
      ini_set('session.cookie_secure', $secure ? '1' : '0');
    }
    session_start();
  }

  /**
   * Release the session lock.
   *
   * PHP's default file session handler holds an exclusive lock on the session
   * file from session_start() until the request ends (or this is called).
   * While the lock is held, any other request carrying the same session cookie
   * (AJAX sub-requests, files piped through PHP, parallel tabs) blocks. The
   * page render below is read-only w.r.t. the session, so we close it first to
   * let concurrent requests from the same logged-in user run in parallel.
   *
   * After this call $_SESSION stays readable in memory; only further writes
   * are no longer persisted, so it must run after all session writes (login,
   * language negotiation, queued messages) have happened.
   */
  public function closeSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
  }

  /**
   * Get all included CSS / JS files.
   *
   * @return array
   *   The included files.
   */
  public function getIncludes() {
    return $this->includes;
  }

  /**
   * Add a CSS / JS file to include.
   *
   * @param $include
   *   The file to include.
   *
   * @param null $type
   *   The type of the file.
   */
  public function addInclude($include, $type = NULL) {
    if ($type == NULL) {
      $ext = explode('.', $include);
      $type = $ext[count($ext) - 1];
    }
    $this->includes[] = array('path' => $include, 'type' => $type);
  }

  /**
   * Check if there are any queued actions in the request.
   */
  public function checkActions() {
    if (!empty($this->request_json->action) && isset($this->request_json->action->value)) {

      $action_value = ($this->request_json->action->value);

      if (isset($action_value)) {

        if (is_array($action_value)) {
          $action = array_pop($action_value);
        }
        else {
          $action = $action_value;
        }

        $vars = array('action' => $action, 'data' => (array) $this->request_json);

        $this->hook('action_' . $action, $vars);
        exit;
      }
    }
  }

  /**
   * Verifies the path and / or creates a candidate path.
   * @param $title
   * @return string
   */
  public function getCandidatePath($title) {
    $candidate_path = \Quanta\Common\Api::normalizePath($title);

    $i = 0;
    while (TRUE) {
      // db()->exists() is the same question a Node would answer, minus building
      // one — which resolves the path, reads the document and runs the
      // node_open hooks just to look at ->exists.
      $exists = $this->db()->exists($candidate_path);
      // If the candidate path already exists, add a progressive number
      // to it until it's free.
      if (!$exists) {
        break;
      }
      else {
        $candidate_path = $candidate_path . '-' . time() . '-' . rand(1000,9999);
      }
    }
    return $candidate_path;
  }

  /**
   * Defines a system directory.
   *
   * @param $name
   *   The sys
   * @param $folder
   * @param string $system_dir
   * @return mixed
   */
  public function sysdir($name, $folder, $system_dir = 'docroot') {
    $this->dir[$name] = $this->dir[$system_dir] . '/' . $folder;
    return $folder;
  }

  /**
   * Creates a temporary directory (if it doesn't exist yet).
   * @param $name
   * @param $folder
   * @return mixed
   */
  public function tmpdir($name, $folder) {
    return $this->sysdir($name, $folder, 'tmp');
  }

  /**
   * Returns the system path of a node (folder).
   *
   * A thin wrapper over the node database's resolver, which owns the four
   * layers this method used to carry inline — the per-request memo, the
   * tmp/cache shard symlink, the derived index and exec(find). They moved to
   * FilesDb::path() so that the resolver is one implementation with one
   * fallback order instead of a caller and a callee each holding half of it,
   * calling each other. This stays because two dozen call sites say nodePath().
   *
   * @param string $folder
   *   The folder (node) to search.
   * @param bool $link
   *   Search also symlinks if true.
   * @param bool $clear_cache
   *   Drop $folder from the per-request memo (all of it when $folder is empty)
   *   and return. Callers do this after a write that can have moved the node.
   *
   * @return string|false|null
   *   The path of the node; FALSE when it does not exist; NULL on a cache
   *   clear, which returns no path because it was asked for none.
   */
  public function nodePath($folder, $link = FALSE, $clear_cache = FALSE) {
    if ($clear_cache) {
      $this->db()->forget($folder);
      return NULL;
    }
    return $this->db()->path($folder, array('link' => $link));
  }

  /**
   * Given a link, returns the system path of the related node (folder).
   *
   * @param bool $link
   *   The link to a node.
   *
   * @return array
   *   The array containing system path(s) to the node.
   */
  public function linkToNode($link) {
    // Find the link target.
    $target = readlink($this->nodePath($link, true));
    // Return the node name the last part of the path of the node.
    $node_name = array_slice(explode('/', $target), -1)[0];
    return $node_name;
  }
}
