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
  public $request_path;
  public $request_json;
  public $request = array();
  public $class_map = array();
  private $modules = array();
  // TODO: maybe we just need modules. Created this var to rearrange them in right order taking care of dependencies.
  private $modules_loaded = array();
  private $includes = array();
  private $context;
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
    // TODO: move request_uri in data.
    $this->setData('request_url', $this->getProtocol() . '://' . $this->host . $this->request_uri);
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
    $this->dir['users'] = $this->dir['docroot'] . '/_users';
    $this->dir['tpl'] = $this->dir['docroot'] . '/_tpl';
	
    // TODO: move to files module.
    $this->dir['tmp_files'] = $this->dir['tmp'] . '/files';
    $this->dir['log'] = $this->dir['tmp'] . '/log';
    // TODO: generation to be done in user and node modules.
    $this->dir['statuses'] = $this->dir['docroot'] . '/_statuses';

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
  public function getLoadedModules() {
    return $this->modules_loaded;
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
    $scan = ($this->scanDirectory($base_dir . '/' . $dir, $attributes));

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
    $hooked = FALSE;
    foreach ($this->getLoadedModules() as $module) {
      $hook = __NAMESPACE__ . '\\' . $module['name'] . '_' . $function;
      if (function_exists( $hook)) {
        $hook($env, $vars);
        $hooked = TRUE;
      }
    }
    return $hooked;
  }


  /**
   * Start client session.
   */
  public function startSession() {
    session_start();
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
    if (isset($this->request_json->action)) {

      if (is_array($this->request_json->action)) {
        $this->request_json->action = array_pop($this->request_json->action);
      }
      $vars = array('data' => (array) $this->request_json);
      $this->hook('action_' . $this->request_json->action, $vars);
      exit;
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
      $node = new Node($this, $candidate_path);
      // If the candidate path already exists, add a progressive number
      // to it until it's free.
      if (!$node->exists) {
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
   * Helper function to retrieve the system path of a node (folder).
   *
   * @param string $folder
   *   The folder name (node name) to retrieve.
   *
   * @return mixed $results
   *   The result of the node search.
   */
  private function findNodePath($folder) {
    // TODO: cleaner way to exclude folders in _modules.
    $findcmd = 'find ' . $this->dir['docroot'] . '/ -type d -name "' . $folder . '" -not -path */_modules* -not -path *.git*';
    // TODO: sometimes getting empty folder. Why? Temporary fix.
    if (empty($folder)) {
      return NULL;
    }
    exec($findcmd, $results);
    return $results;
  }

  function getLastPathSegment($path) {
    // Regular expression to match the last valid part of a URL path excluding files
    $pattern = '/([^\/\?#]*[^\/\?#\.][^\/\?#]*|[^\/\?#]+)(?:[\?#]|$)/';

    if ($path == NULL) {
      return NULL;
    }
    // Perform the regex match
    if (preg_match($pattern, $path, $matches)) {
      return $matches[1];
    } else {
      return null;
    }
  }

  /**
   * Returns the system path of a node (folder).
   *
   * @param $folder
   *   The folder (node) to search.
   * @param bool $link
   *   Search also symlinks if true.
   *
   * @return mixed
   *   The path of the node.
   */
  public function nodePath($folder, $link = FALSE) {
    static $node_paths = array();
    // Regular expression to match the last valid part of a URL path
    $pattern = '/(?:.*\/)?([^\/\?#\.]+)(?:\/[^\/\?#]*)?(?:[\?#]|$)/';
    //$pattern = '/(?:\/([^\/\?#]*[^\/\?#\.][^\/\?#]*))(?:[\?#]|$)/';
    $cache_exists = FALSE;
    // Perform the regex match
    $folder = $this->getLastPathSegment($folder);

    if ($folder == NULL) {
      return NULL;
    }
    // We use a static variable to lookup nodes paths only once.
    if (isset($node_paths[$folder])) {
      $node_path_link = $node_paths[$folder];
      $cache_exists = TRUE;
      //return $node_paths[$folder];
    } else {
      $node_path_link = Cache::getStoredNodePath($this, $folder);
    }

    $node_path = @readlink($node_path_link);
    if ($node_path == false) {
      // Use find to locate the node's directory in the file system.
      // TODO: run a sanity check that there is only one folder or throw error instead?
      $results = $this->findNodePath($folder);
      $found_folders = array();

      if (empty($results)) {
        return FALSE;
      }
      // Check that there are not duplicate folders. Don't count symlinks.
      foreach ($results as $i => $res) {
        if (is_dir($results[$i]) && ($link ? true : !is_link($results[$i]))) {
          $found_folders[] = $results[$i];
          $node_path = $results[$i];
        } else {
          unset($results[$i]);
        }
      }

      if (count($found_folders) > 1) {
        new Message($this,
          t('Warning: there is more than one folder named !folder: <br/>!folds<br>Check integrity!',
            array(
              '!folder' => $folder,
              '!folds' => var_export($found_folders, 1),
            )
          ));
      }
    }

    if (!$cache_exists) {
      $node_paths[$folder] = Cache::storeNodePath($this, $node_path);
    }
    return $node_path;

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
