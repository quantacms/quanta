<?php
define("DIR_INACTIVE", '_');
define("DIR_ALL", 'all');
define("DIR_DIRS", 'dirs');
define("DIR_FILES", 'files');
define("DIR_MODULES", 'modules');
define("QUANTA_ROOT", '__ROOT__');
define("GLOBAL_SEPARATOR", '@\@/@\@/@\@/@');

/**
 * Class Environment
 * This class represents an Environment with his directories etcetera.
 */
class Environment extends DataContainer {
  public $host = array();
  public $dir = array();
  public $request_uri;
  public $request_path;
  public $request_json;
  public $request = array();
  private $modules = array();
  // TODO: maybe we just need modules. Created this var to rearrange them in right order taking care of dependencies.
  private $modules_loaded = array();
  private $includes = array();
  private $context;

  /**
   * Environment constructor.
   * @param string $host
   * @param string $request_uri
   */
  public function __construct($host = NULL, $request_uri = NULL, $docroot = NULL) {


    $this->host = (($host == NULL) ? strtolower($_SERVER['HTTP_HOST']) : $host);
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
      $this->request_path = $this->request[count($this->request) - 2];
    }

    if ($docroot == NULL) {
      $docroot = $_SERVER['DOCUMENT_ROOT'];
    }
    // TODO: move request_uri in data.
    $this->setData('request_url', $this->getProtocol() . '://' . $this->host . $this->request_uri);
    $this->dir['quanta'] = $docroot;
    $this->dir['sites'] = $this->dir['quanta'] . '/sites';
    $this->dir['engine'] = $this->dir['quanta'] . '/engine';
    $this->dir['docroot'] = $this->dir['sites'] . '/' . $this->host;
    $this->dir['tmp'] = $this->dir['engine'] . '/tmp/' . $this->host;
    $this->dir['vendor'] = $this->dir['quanta'] . '/vendor';
    $this->dir['modules_core'] = $this->dir['engine'] . '/code/php/core';
    $this->dir['modules_custom'] = $this->dir['docroot'] . '/_modules';
    // TODO: move to files module.
    $this->dir['tmp_files'] = $this->dir['tmp'] . '/files';
    $this->dir['log'] = $this->dir['tmp'] . '/log';
    // TODO: move to user module.
    // TODO: generation to be done in user and node modules.
    $this->dir['statuses'] = $this->dir['docroot'] . '/_statuses';

    if (isset($_REQUEST['json'])) {
      $this->request_json = json_decode($_REQUEST['json']);
    }
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
   * @return string mixed
   */
  public function getRequestedPath() {
    return $this->request_path;
  }

  /**
   * Load core modules.
   */
  private function loadCoreModules() {
    $core_modules = $this->scanDirectory($this->dir('modules_core') , array('type' => DIR_MODULES));
    $this->setModules('core', $core_modules);
  }

  /**
   * Load contributed modules.
   */
  private function loadContribModules() {
    $contrib_modules = $this->scanDirectory($this->dir['docroot'] . '/_modules', array('type' => DIR_MODULES));
    $this->setModules('contrib', $contrib_modules);
  }

  /**
   * Setup modules.
   *
   * @param $mod_type
   * @param $modules
   */
  public function setModules($mod_type, $modules) {
    $module_path = $mod_type == 'core' ? $this->dir('modules_core') : $this->dir('modules_custom');

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
   * @return mixed
   */
  public function getModule($module) {
    return $this->modules[$module];
  }

  /**
 * Get all modules.
 *
 * @return array
 */
  public function getModules() {
    return $this->modules;
  }

  /**
   * Get all loaded modules.
   *
   * @return array
   */
  public function getLoadedModules() {
    return $this->modules_loaded;
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
   * @param $module
   *   The module to run before the depending one.
   */
  public function dependsFrom($module) {
    $this->runModule($this->getModule($module));
  }

  /**
   * Run (Load) a module.
   * @param $module
   */
  public function runModule($module) {
    $includes = array('api', 'hook', 'wiki', 'qtags');
    $module_path = $module['path'] . '/' . $module['name'] . '.module';
    require_once($module_path);
    $this->modules_loaded[$module['name']] = $module;

    /**
     * Check all module's inclusions.
     * TODO: useless waste of resources - include files in .module.
     */
    foreach ($includes as $include_type) {
      $include_path = $module['path'] . '/' . $module['name'] . '.' . $include_type . '.inc';
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
      $attributes['exclude_dirs'] = DIR_INACTIVE;
    }
    if (!isset($attributes['type'])) {
      $attributes['type'] = DIR_ALL;
    }

    $dirs = array_diff(scandir($base_dir), array('.', '..', '.git'));

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


      if ($attributes['type'] == DIR_DIRS && !is_dir($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }
      elseif ($attributes['type'] == DIR_FILES && !is_file($base_dir . '/' . $dir)) {
        unset ($dirs[$k]);
      }
      elseif ($attributes['type'] == DIR_MODULES &&
        (!is_dir($base_dir . '/' . $dir) || !is_file($base_dir . '/' . $dir . '/' . $dir . '.module'))
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
  public function scanDirectoryDeep($base_dir, $dir, $dirs = array(), $attributes = array('exclude_dirs' => DIR_INACTIVE, 'type' => DIR_ALL, 'level' => 'leaf'), $depth = 0) {
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

  // Load all system directories.
  public function load() {
    $this->loadCoreModules();
    $this->loadContribModules();
  }

  // Return a system directory.

  /**
   * @param $variable
   * @return mixed
   */
  public function dir($variable) {
    return $this->dir[$variable];
  }

  /**
   * Hook function - will look for all modulename_function in all active modules.
   *
   * @param $function
   * @param array $vars
   * @return bool
   */
  public function hook($function, &$vars = array()) {
    $env = &$this;
    foreach ($this->getLoadedModules() as $module) {
      $hook = $module['name'] . '_' . $function;
      if (function_exists($hook)) {
        $hook($env, $vars);
      }
    }
    return TRUE;
  }


  /**
   * Start the PHP session with the client.
   */
  public function startSession() {
    session_start();
  }

  /**
   * Get all included CSS / JS files.
   * @return array
   */
  public function getIncludes() {
    return $this->includes;
  }

  /**
   * Add a CSS / JS file to include.
   * @param $include
   * @param null $type
   */
  public function addInclude($include, $type = NULL) {
    if ($type == NULL) {
      $ext = explode('.', $include);
      $type = $ext[count($ext) - 1];
    }
    $this->includes[] = array('path' => $include, 'type' => $type);
  }

  /**
   * Check if there are any queued actions in the requeqst.
   */
  public function checkActions() {
    if (isset($this->request_json->action)) {

      if (is_array($this->request_json->action)) {
        $this->request_json->action = array_pop($this->request_json->action);
      }
      $vars = array('data' => (array) $this->request_json);
      $this->hook('action_' . $this->request_json->action, $vars);
    }
  }

  /**
   * Verifies the path and / or creates a candidate path.
   * @param $title
   * @return string
   */
  public function getCandidatePath($title) {
    $candidate_path = normalizePath($title);

    $i = 0;
    while (TRUE) {
      $node = new Node($this, $candidate_path);
      // If the candidate path already exists, add a progressive number
      // to it until it's free.
      if (!$node->exists) {
        break;
      }
      else {
        $i++;
        $candidate_path = $candidate_path . '-' . $i;
      }
    }
    return $candidate_path;
  }

  /**
   * Defines a system directory.
   * @param $name
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
   * @param $folder
   * @return $results
   */
  private function findNodePath($folder) {

    $findcmd = 'find ' . $this->dir['docroot'] . '/ -name "' . $folder . '"';
    // TODO: sometimes getting empty folder. Why? Temporary fix.
    if (empty($folder)) {
      return NULL;
    }
    exec($findcmd, $results);
    return $results;
  }

  /**
   * Returns the system path of a node (folder).
   *
   * @param $folder
   *   The folder (node) to search.
   * @param bool $link
   *   Search also symlinks if true.
   * @return mixed
   *   The path of the node.
   */
  public function nodePath($folder, $link = FALSE) {
    if ($nodepath = Cache::getStoredNodePath($this, $folder)) {
      return readlink($nodepath);
    }
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
        $result = $results[$i];
      }
      else {
        unset($results[$i]);
      }
    }
    if (count($found_folders) > 1) {
      new Message($this, 'Warning: there is more than one folder named ' . $folder . ': <br/>' . var_export($found_folders, 1) . '<br>Check integrity!');
    }
    elseif (empty($result)) {
      new Message($this, 'Warning: there is no folder named ' . $folder . '<br>Check integrity!');
    }
    else {
      Cache::storeNodePath($this, $result);
      return $result;
    }

    // TODO: possible?
    return NULL;
  }

  /**
   * Given a link, returns the system path of the related node (folder).
   * @param bool $link
   */
  public function linkToNode($link) {
    // Find the link target.
    $target = readlink($this->nodePath($link, true));
    // Return the node name the last part of the path of the node.
    $node_name = array_slice(explode('/', $target), -1)[0];
    return $node_name;
  }

  /**
   * Checks if currently loaded environment is for a file, and renders it if so.
   */
  public function checkFile() {

		// Support for letsencript https certificates.
		if ($this->request[2] == 'acme-challenge') {
			readfile('/' . trim($this->dir['docroot'] . implode('/', $this->request), '/'));
		  die();
		}
    // TODO: redo the whole shit.
    if (strpos($this->request[count($this->request) - 1], '.') > 0) {
      $filename = $this->request[count($this->request) - 1];
      $nodepath = Cache::getStoredNodePath($this, $this->request[1]);
      $file = $nodepath . '/' . urldecode($filename);
      if (is_file($file)) {
        header('Content-Type: ' . mime_content_type($file));
        $mods = array_flip(apache_get_modules());
        if (isset($mods['mod_xsendfile'])) {
          // TODO: support for xsendfile.
          readfile($file);
        }
        else {
          //TODO : slow, insecure...
          readfile($file);
        }
        die();
      }
      elseif ($filename == 'favicon.ico') {
        die("No favicon available.");
      }
    }
  }
}
