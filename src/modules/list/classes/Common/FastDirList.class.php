<?php
namespace Quanta\Common;

/**
 * Class FastDirList
 * This class extends DirList but uses fast node loading to skip heavy hooks and access checks.
 * Use it ONLY for backend performance-critical lists where full Node functionality isn't needed.
 * 
 * PERFORMANCE: This class overrides the parent constructor to completely bypass
 * NodeFactory::load() and nodePath() calls, which use expensive exec('find ...')
 * commands for uncached nodes. Instead, it resolves paths directly from the filesystem.
 */
class FastDirList extends DirList {

  /**
   * Wall-clock seconds the prefix-walk may spend on a single name.
   *
   * The walk is a fallback for when the node index cannot answer. Uncapped it
   * runs until PHP's max_execution_time kills the whole request, turning a slow
   * page into a 500. Bounded, the caller gets FALSE and can degrade instead.
   */
  const RESOLVE_BUDGET_SECONDS = 2.0;

  /**
   * Wall-clock seconds one REQUEST may spend prefix-walking, in total.
   *
   * A render resolves thousands of distinct names, so it is their sum, not any
   * single walk, that reaches max_execution_time. Once spent, resolution falls
   * back to the cheap layers only -- the same thing
   * `path($name, ['search' => FALSE])` already means.
   */
  const REQUEST_WALK_BUDGET_SECONDS = 5.0;

  /**
   * Seconds spent prefix-walking so far.
   *
   * Static because the budget is per request, not per list, and under php-fpm
   * statics are torn down between requests. A long-lived SAPI would accumulate
   * across requests and eventually stop walking for good.
   *
   * @var float
   */
  private static $walk_spent = 0.0;

  /**
   * Whether the exhaustion above has already been logged -- once, not once per
   * name, since past the budget every remaining lookup would fire the same line.
   *
   * @var bool
   */
  private static $walk_budget_reported = FALSE;

  /**
   * Deadline for the current prefix-walk, or NULL when none is running.
   *
   * @var float|null
   */
  private $resolve_deadline = NULL;

  /**
   * Override the constructor to bypass NodeFactory::load() for the parent node.
   * The parent ListObject constructor calls NodeFactory::load() which triggers
   * nodePath() -> db()->path() -> exec('find ...') for uncached nodes.
   * With many list items (e.g. hundreds of booking status folders), this causes
   * hundreds of sequential shell 'find' commands, each taking seconds.
   */
  public function __construct(&$env, $path, $tpl, $attr_arr = array(), $module = NULL) {
    $this->env = $env;
    if (!empty($tpl)) {
      $this->tpl = strtolower($tpl);
    }
    // Check if there are tree items that should not be expanded.
    if (isset($attr_arr['exclude_tree'])) {
      $this->exclude_tree = array_flip(explode(',', $attr_arr['exclude_tree']));
    }

    // Check if the template is in a different module than the default.
    if (!empty($module)) {
      $this->module = $module;
    }

    // Support for "root" list.
    if ($path == self::LIST_ROOT) {
      $this->path = $env->dir['docroot'];
      $this->setNode(NULL);
    }
    elseif ($path == Node::NODE_NEW) {
      $this->path = NULL;
      $this->setNode(NULL);
    }
    elseif ($path == NULL) {
      $this->setNode(NodeFactory::current($this->env));
      $this->path = $this->getNode()->path;
    }
    else {
      // FAST PATH: Try to resolve the node path without exec('find').
      // First try the nodePath cache (static + symlink cache) which is cheap.
      // If that fails, try to find the path by walking known parent structures.
      $resolved_path = $this->fastResolvePath($path);
      
      if ($resolved_path && is_dir($resolved_path)) {
        // Create a lightweight node from the resolved path, bypassing hooks
        $node = NodeFactory::fastLoadFromRealPath($this->env, $resolved_path);
        $this->setNode($node);
        $this->path = $resolved_path;
      } else {
        // The node path does not exist. Do NOT fallback to NodeFactory::load()
        // as that would trigger exec('find'). Just create an empty node.
        $this->path = NULL;
        $empty_node = new Node($this->env, Node::NODE_NEW);
        $empty_node->setName($path);
        $this->setNode($empty_node);
      }
    }

    foreach ($attr_arr as $attr_key => $attr_val) {
      $this->setData($attr_key, $attr_val);
    }

    $this->setData('list_html_tag', !empty($this->getData('list_html_tag')) ? $this->getData('list_html_tag') : 'ul');
    $this->setData('list_item_html_tag', !empty($this->getData('list_item_html_tag')) ? $this->getData('list_item_html_tag') : 'li');

    // Skip sortable setup (not needed for fast backend lists)
    
    $lang = isset($attr_arr['language']) ? $attr_arr['language'] : null;
    $this->load($lang);
  }

  /**
   * Fast resolve a node path without using exec('find').
   * 
   * Strategy:
   * 1. Check static cache and symlink cache via nodePath (cheap readlink check)
   * 2. If the node name contains a parent prefix (e.g. 'rome-bookings-paid' is child of 'rome-bookings'),
   *    try to resolve by finding the parent and appending the child name.
   * 3. Try common known locations (db/businesses, db, pages, backoffice, etc.)
   * 
   * @param string $name The node name to resolve
   * @return string|false The resolved path or false
   */
  private function fastResolvePath($name) {
    // 1. Try the cache symlink directly (this is the cheap part of nodePath)
    $cached_link = Cache::getStoredNodePath($this->env, $name, TRUE);
    if ($cached_link) {
      $resolved = @readlink($cached_link);
      if ($resolved && is_dir($resolved)) {
        return $resolved;
      }
    }

    // 1.5. The node database, cheap layers only: 'search' => FALSE stops it
    // before the exec(find) this class exists to avoid. Where an index is
    // serving, this resolves any cold name in one probe and the prefix-walk
    // below never runs.
    $db_path = $this->env->db()->path($name, array('search' => FALSE));
    if ($db_path !== FALSE && is_dir($db_path)) {
      Cache::storeNodePath($this->env, $db_path);
      return $db_path;
    }

    // Request-wide budget spent: stop walking. FALSE here is not a claim that
    // the node is absent, it is the same "not found cheaply" the two layers
    // above produce, and the caller treats all three alike.
    if (self::$walk_spent >= self::REQUEST_WALK_BUDGET_SECONDS) {
      if (!self::$walk_budget_reported) {
        self::$walk_budget_reported = TRUE;
        error_log(sprintf(
          'FastDirList: prefix-walk budget of %.1fs exhausted; resolving from the '
          . 'cache and index only for the rest of this request. This means the node '
          . 'index is not serving -- check qdbstat.',
          self::REQUEST_WALK_BUDGET_SECONDS
        ));
      }
      return false;
    }

    // 2. Try to find the directory by walking the known directory tree structure.
    // Most booking/business nodes follow a naming pattern where child node names
    // contain the parent name as a prefix. E.g.:
    //   'rome-bookings-paid' is at 'rome-bookings/rome-bookings-paid'
    //   'rome-bookings' is at 'rome/rome-bookings'
    // We can recursively resolve parent paths.
    $docroot = $this->env->dir['docroot'];

    // Try direct known locations first
    $known_bases = array(
      $docroot . '/db/businesses',
      $docroot . '/db',
      $docroot . '/pages',
      $docroot . '/backoffice',
      $docroot,
    );

    // Search by walking down the name hierarchy
    // E.g. for 'rome-sunset-22-bookings-paid', try to find it under a parent
    // whose name is a prefix of this one
    $walk_started = microtime(TRUE);
    $this->resolve_deadline = $walk_started + min(
      self::RESOLVE_BUDGET_SECONDS,
      self::REQUEST_WALK_BUDGET_SECONDS - self::$walk_spent
    );
    foreach ($known_bases as $base) {
      $found = $this->findInDirectory($base, $name, 4);
      if ($found) {
        self::$walk_spent += microtime(TRUE) - $walk_started;
        $this->resolve_deadline = NULL;
        // Cache the found path for future use
        Cache::storeNodePath($this->env, $found);
        return $found;
      }
    }
    self::$walk_spent += microtime(TRUE) - $walk_started;
    $this->resolve_deadline = NULL;

    return false;
  }

  /**
   * Recursively search for a directory name within a base directory,
   * limited to a certain depth.
   * 
   * @param string $base_dir The directory to search in
   * @param string $target_name The directory name to find
   * @param int $max_depth Maximum recursion depth
   * @return string|false The full path if found, false otherwise
   */
  private function findInDirectory($base_dir, $target_name, $max_depth) {
    if ($max_depth <= 0 || !is_dir($base_dir)) {
      return false;
    }
    // Give up rather than run into max_execution_time. Checked on entry so the
    // cost of the check is bounded by the number of directories entered, not by
    // the number of entries scanned.
    if ($this->resolve_deadline !== NULL && microtime(TRUE) >= $this->resolve_deadline) {
      return false;
    }

    // Check if target exists directly under this directory
    $candidate = $base_dir . '/' . $target_name;
    if (is_dir($candidate) || (is_link($candidate) && is_dir(readlink($candidate)))) {
      return $candidate;
    }

    // Scan subdirectories - but only follow dirs whose name could be a parent prefix
    // of our target (optimization: if target is 'rome-bookings-paid', only enter 
    // dirs like 'rome', 'rome-bookings', etc.)
    $scan = @scandir($base_dir);
    if ($scan === false) {
      return false;
    }

    foreach ($scan as $entry) {
      if ($entry === '.' || $entry === '..' || $entry[0] === '_' || $entry[0] === '.') {
        continue;
      }
      // Only recurse into directories whose name is a prefix of the target
      // (the target should be nested under its parent in the path structure)
      if (strpos($target_name, $entry) === 0 && is_dir($base_dir . '/' . $entry)) {
        $found = $this->findInDirectory($base_dir . '/' . $entry, $target_name, $max_depth - 1);
        if ($found) {
          return $found;
        }
      }
    }

    return false;
  }

  /**
   * Override the load method to use FastNodeLoading.
   */
  public function load($lang = null) {
    // Use the already-resolved path from the node (set during construction)
    $parent_path = $this->path;
    
    if (empty($parent_path) || !is_dir($parent_path)) {
      return;
    }
    
    // Children from the index when the name resolves to this path, otherwise
    // the same directory scan. The nodes below are still built from real
    // paths, so this only replaces the listing, not the loading.
    $scan = $this->env->scanNodeDirectory($parent_path, $this->getListNodeName(), array('type' => $this->scantype, 'exclude_dirs' => Environment::DIR_INACTIVE));
    
    foreach ($scan as $dir) {
      if ($this->node->getName() == $dir && !$this->getData('list_father')) {
        continue;
      }
      
      // Fast load bypassing hooks and environment path search
      $node_path = $parent_path . '/' . $dir;
      $node = NodeFactory::fastLoadFromRealPath($this->env, $node_path, $this->language);
      
      if ($node->exists) {
        // Validate the item against list filters
        if ($this->validateListItem($node)) {
          $this->addItem($node);
        }
      }
    }
    
    $this->loadAttributes();
  }

  /**
   * Override loadAttributes to be accessible from load().
   */
  private function loadAttributes() {
    // Set the sort order.
    if (!empty($this->getData('sort'))) {
      $this->sort = $this->getData('sort');
      if ($this->sort == 'random') {
        //shuffle($this->items);
      } else {
        uasort($this->items, array($this, 'sortBy'));
        if (!empty($this->getData('asc'))) {
          $this->items = array_reverse($this->items);
        }
      }
    }

    // Reverse the sort order.
    if (!empty($this->getData('reverse'))) {
      $this->items = array_reverse($this->items);
    }

    // Sets a limit.
    if (!empty($this->getData('limit'))) {
      $this->limit = $this->getData('limit');
    }
  }
}
