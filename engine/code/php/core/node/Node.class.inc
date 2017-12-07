<?php
define('NODE_ACTION_VIEW', 'node_view');
define('NODE_ACTION_ADD', 'node_add');
define('NODE_ACTION_EDIT', 'node_edit');
define('NODE_ACTION_DELETE', 'node_delete');
define('NODE_ACTION_DELETE_FILE', 'file_delete');
define('NODE_STATUS_DRAFT', 'node_status_draft');
define('NODE_STATUS_PUBLISHED', 'node_status_published');
define('NODE_STATUS_UNPUBLISHED', 'node_status_unpublished');
define('NODE_NEW', '__NEW__');

date_default_timezone_set('UTC');
/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class Node extends JSONDataContainer {
  public $title;
  public $author;
  public $body = NULL;
  public $teaser = NULL;
  public $content = NULL;
  public $exists;
  public $permissions;
  public $status;
  public $timestamp;
  public $father = NULL;
  public $thumbnail = NULL;
  protected $lineage = array();
  public $tpl_node = NULL;
  public $tpl = NULL;
  public $forbidden = FALSE;

  /**
   * Constructs a node object.
   * @param $env
   * @param $name
   * @param null $father
   */
  public function __construct(&$env, $name, $father = NULL, $tpl_node = NULL, $language = NULL) {
    $this->env = $env;
    $this->json = new stdClass();
    // Load node's language.
    if (!empty($language)) {
      $this->setLanguage(!empty($language) ? $language : $this->env->getLanguage());
    }
    // Load node's father (parent folder).
    if ($father != NULL) {
      $this->father = NodeFactory::load($env, $father);
    }

    // Checking if this is not a new node.
    if ($name != NODE_NEW) {
      $this->setName(strtolower($name));
      // Load node from cache (RAM) if it has been already loaded.
      if ($cached = Cache::get($this->env, 'node', $this->name)) {
        foreach (get_object_vars($cached) as $key => $value) {
          $this->$key = $value;
        }
        $this->env->hook('node_load_cache', array(
          'node' => &$this,
        ));
        $this->exists = TRUE;
      }
      // If node is not in cache, load it from file system.
      else {
        $result = $this->env->nodePath($this->getName());
        // TODO: unify path and realpath.
        $this->path = $result;
        $this->realpath = $result;
        $this->exists = $result != NULL;
      }

    }
    // ...Adding a new node. No values to load.
    else {
      $this->setName(NODE_NEW);
      $this->exists = FALSE;
    }

    // Use another node for rendering.
    if ($tpl_node != NULL) {
      $this->tpl_node = $tpl_node;
    }

    if ($this->exists || $father != NULL) {
      $this->load();
    }

  }

  /**
   * Load the node from json.
   * TODO: move standard part into JSONDataContainer.
   */
  public function loadJSON() {
    // Look for a language version.
    if (is_file($this->path . '/data_' . $this->getLanguage() . '.json')) {
      $json_path = ($this->path . '/data_' . $this->getLanguage() . '.json');
    }
    // Look for a language neutral version.
    elseif (is_file($this->path . '/data.json')) {
      $json_path = ($this->path . '/data.json');
    }
    // Impossible to load json. Error or empty Node.
    else {
      $this->json = new StdClass;
      return;
    }

    $this->json = (object) json_decode(file_get_contents($json_path));

    // Load the node teaser from JSON.
    if (isset($this->json->teaser)) {
      $this->setTeaser($this->json->teaser);
    }
    // Load the node author from JSON.
    if (isset($this->json->author)) {
      $this->setAuthor($this->json->author);
    }
    // Load the node body from JSON.
    if (isset($this->json->body)) {
      $this->setBody($this->json->body);
    }
    // Load the node status from JSON.
    if (!empty($this->json->status)) {
      $this->setStatus($this->json->status);
    }
    // Use published as a fallback. TODO: is this correct?
    else {
      $this->setStatus(NODE_STATUS_PUBLISHED);
    }
    // Load the node title from json.
    if (isset($this->json->title)) {
      $this->setTitle($this->json->title);
    }
    // Load the node timestamp / craeted time from json.
    if (isset($this->json->timestamp)) {
      $this->setTimestamp($this->json->timestamp);
    }
    // Load the node Thumbnail from json.
    if (isset($this->json->thumbnail)) {
      $this->setThumbnail($this->json->thumbnail);
    }
  }

  /**
   * Check if the node is published.
   */
  public function isPublished() {
    // Nodes starting with _ are system nodes, not public by default.
    if (substr($this->name, 0, 1) == '_') {
      return FALSE;
    }
    return ($this->getStatus() == NODE_STATUS_PUBLISHED);
  }

  /**
   * Update node's json values.
   */
  public function updateJSON($ignore = array()) {
    // Here we generate the json value, using the node object's values.
    $this->json->name = $this->getName();
    $this->json->teaser = $this->getTeaser();
    $this->json->author = $this->getAuthor();
    $this->json->body = $this->getBody();
    $this->json->title = $this->getTitle();
    $this->json->thumbnail = $this->getThumbnail();
    $this->json->timestamp = $this->getTimestamp();
    $this->json->status = $this->getStatus();
  }

  //*** TEMPORARY FIX ***
  public function getAttributeJSON($attr_name){
    if (isset($this->json->$attr_name)) {
      return $this->json->$attr_name;
    }
  }

  /**
   * Load node with its variables internally.
   */
  public function load() {
    $this->fullpath = str_replace($this->env->dir['docroot'], '', $this->realpath);
    $this->env->hook('node_load', array(
      'node' => &$this,
    ));

    if ($this->body == NULL && $this->exists) {
      $this->buildContent();
    }
    if ($this->exists && ($this->getTimestamp() == NULL)) {
      // TODO: Sometimes filemtime returns error.
      @$this->setTimestamp(filemtime($this->realpath));
    }
  }

  /**
   * Builds node content.
   */
  public function buildContent() {
    // Load data from JSON file if possible.
    $this->loadJSON();
    $this->env->hook('node_build', array(
      'node' => &$this,
    ));
    Cache::set($this->env, 'node', $this->name, $this);
  }

  /**
   * Check if the node has a specific parent in its lineage.
   * @param $name
   * @return bool
   */
  public function hasParent($name) {
    $ret = false;
    $lineage = $this->getLineage();
     
    foreach ($lineage as $lineage_node) {
      if ($lineage_node->getName() == $name) {
        $ret = true;
      }
    }
    return $ret;
  }

  /**
   * Check if the node has any children.
   * @return bool
   */
  public function hasChildren() {
    $scan = $this->env->scanDirectory($this->realpath);
    foreach ($scan as $dir) {
      if (is_dir($this->realpath . '/' . $dir)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if the node's folder has a subfolder (subnode).
   * @param $name
   * @return bool
   */
  public function hasChild($name) {

    return (empty($name) ? FALSE : is_dir($this->realpath . '/' . $name));
  }

  /**
   * Checks if the node is the currently viewed one.
   * @return bool
   */
  public function isCurrent() {
    return ($this->name == $this->env->getRequestedPath());
  }

  /**
   * Validates this node before saving it.
   * TODO: put into a hook!
   */
  public function validate($form_data = array()) {
    $valid = TRUE;
    $author = new User($this->env, $this->getAuthor());

    if ($this->getTitle() == '') {
      new Message($this->env, 'Node title can not be empty.', MESSAGE_WARNING);
      $valid = FALSE;
    }
    if (!$author->exists) {
      new Message($this->env, 'User ' . $this->getAuthor() . ' is not a valid user!', MESSAGE_WARNING);
      $valid = FALSE;
    }

    return $valid;
  }


  /**
   * Save this node on the file system.
   */
  public function save() {
    if (empty($this->realpath)) {
      $this->realpath = $this->env->nodePath($this->getFather()->getName()) . '/' . $this->getName();
    }
    $this->env->hook('node_preinsert', array(
      'node' => &$this,
    ));
    $this->updateJSON();
    $this->saveJSON(array('path', 'fullpath', 'exists', 'realpath', 'father', 'tmp_files_dir'));

    $this->env->hook('node_insert', array(
      'node' => &$this,
    ));
  }

  public function getBody() {
    return $this->body;
  }

  public function setBody($body) {
    $this->body = $body;
  }

  public function getStatus() {
    return $this->status;
  }

  public function setStatus($status) {
    $this->status = $status;
  }

  /**
   * Returns the teaser of the node. Normalized and with tags excluded.
   * @param int $maxlength
   * @return mixed
   */
  public function getTeaser($maxlength = 300) {
    if (strlen(trim($this->teaser)) > 0) {
      return preg_replace('/\[[^>]*\]/', '', strip_tags($this->teaser));
    }
  }

  /**
   * Return the node's title.
   *
   * @return string title
   *   The Node's title.
   */
  public function getTitle() {
    return $this->title;
  }

  /**
   * Set up the node's title.
   *
   * @param string $title
   *   The Node's title.
   */
  public function setTitle($title) {
    $this->title = $title;
  }

  public function getContent() {
    // TODO: < and > break in the body. Urgent fix to do.
    return '<h1>' . $this->getTitle() . '</h1>' . $this->getBody();
  }

  public function setAuthor($author) {
    $this->author = $author;
  }

  public function getAuthor() {
    return ($this->author == NULL) ? USER_ANONYMOUS : $this->author;
  }

  /**
   * Delete this node and disable it by adding __ to the folder.
   */
  public function delete() {
    $np = explode('/', $this->realpath);
    $rmname = '__' . $np[count($np) - 1] . '_' . time();
    $np[count($np) - 1] = $rmname;
    // Delete file is indeed not an immediate deletion: it adds __ to folder name.
    // This is useful in order to recover a node that was accidentally deleted.
    unlink($this->path);
    rename($this->realpath, implode('/', $np));
    new Message($this->env, 'user deleted this page: ' . $this->name . '.', MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');
  }

  /**
   * Delete this node definitely from the file system.
   * Will delete all subnodes and subfiles. Use with EXTREME care.
   */
  public function deleteHard() {
    // TODO: maybe this function is too dangerous to really enable it.
  }

  /**
   * Set the node's HTML content.
   * @param $content
   */
  public function setContent($content) {
    $this->content = $content;
  }

  /**
   * Set the node's teaser.
   * @param $teaser
   */
  public function setTeaser($teaser) {
    $this->teaser = strip_tags($teaser);
  }

  /**
   * Builds a complete lineage of the node, from its root directory.
   * Useful for breadcrumbs.
   */
  public function buildLineage() {
    if (!empty($this->lineage)) {
      return;
    }

    if ($this->isHome()) {
      return;
    }

    // TODO: dismiss fullpath.
    // TODO: redo this.
    $bca = explode('/', $this->fullpath);
    foreach ($bca as $bread_node) {
      if ($bread_node == '') {
        continue;
      }
      // TODO: use nodefactory without a loop.
      $n = new Node($this->env, $bread_node);
      if (!$n->exists) {
        break;
      }
      $this->lineage[] = $n;
    }
  }

  /**
   * Generate the template for displaying the node.
   * Eventually use tpl.html files (with dashed depth).
   */
  public function buildTemplate() {
    $user = UserFactory::current($this->env);

    // There is the possibility to use another node as a base for the template.
    // TODO: maybe we are not using this anymore?
    if (isset($this->tpl_node)) {
      $tpl_node = new Node($this->env, $this->tpl_node);
      if ($user->checkAccess($tpl_node, NODE_ACTION_VIEW)) {
        $this->tpl = $tpl_node->getBody();
      }
      // TODO: should not be there, but in access check hooks.
      else {
        print "Error loading template: access denied";
        die();
      }
    }

    else {
      $this->tpl = $this->getContent();
      $tpl = array();
      $tpl_level = 0;
      // a template in the same directory has always priority.
      if (is_file($this->realpath . '/tpl.html')) {
        $this->tpl = file_get_contents($this->realpath . '/tpl.html');
      }
      else {
        foreach ($this->lineage as $lineage) {

          $tpl_level++;
          if (is_file($lineage->path . '/tpl.html')) {
            $this->tpl = file_get_contents($lineage->path . '/tpl.html');
          }

          $min = '';

          // We support 5 levels of sub-level templates for now.
          // level 0 = tpl.html
          // level 1 = tpl-.html
          // level 2 = tpl--.html
          // etc...

          for ($i = 1; $i <= 6; $i++) {
            $min .= '-';
            $file = $lineage->path . '/tpl' . $min . '.html';
            if (file_exists($file)) {
                $tpl[$tpl_level + $i] = $file;
            }
          } 
        }

        // check if there is a sub-level template.
        if (isset($tpl[$tpl_level])) {
          $this->tpl = file_get_contents($tpl[$tpl_level]);
        }
      }
    }
  }

  /**
   * Check if current node is the homepage node.
   * @return bool
   */
  public function isHome() {
    return $this->name == 'home';
  }

  public function getLineage() {
    return $this->lineage;
  }

  /**
   * Get the formatted datetime of the node.
   * @return bool|string
   */
  public function getDateTime() {
    return date('d M Y - H:i:s', $this->getTimestamp());
  }

  /**
   * Get the formatted date of the node.
   * @return bool|string
   */
  public function getDate() {
    // TODO: warning thrown.
    date_default_timezone_set('UTC');

    return date('d-m-Y', $this->getTimestamp());
  }

  /**
   * Get the timestamp of the node.
   * @return mixed
   */
  public function getTimestamp() {
    return $this->timestamp;
  }

  /**
   * Set the timestamp of the node.
   * @param $timestamp
   */
  public function setTimestamp($timestamp) {
    $this->timestamp = $timestamp;
  }

  /**
   * Get the formatted timestamp of the node.
   * @return bool|string
   */
  public function getTime() {
    return date('H:s', $this->getTimestamp());
  }

  /**
   * Temporary.
   */
  public function render() {
    $content = str_replace(chr(13), '', $this->getBody());
    return str_replace('[CONTENT]', $content, $this->tpl);
  }

  /**
   * Get all the permissions for this node.
   * @return mixed
   */
  public function getPermissions() {
    $this->loadPermissions();
    return $this->permissions;
  }

  /**
   * Get all the permissions for this node.
   * @return mixed
   */
  public function getPermission($perm) {
    $permissions = $this->getPermissions();
    if (!empty($permissions[$perm])) {
      return $permissions[$perm];
    }
    else {
      return NULL;
    }
  }

  /**
   * TODO: move in access module.
   * Load and construct permissions for this node.
   */
  private function loadPermissions() {
    $grants = array();
    $this->buildLineage();
    // Navigate the whole tree gathering real permissions on the node.
    $lineage = $this->getLineage();
    foreach ($lineage as $lineage_node) {
      if (!empty($lineage_node->json->permissions)) {
        $lineage_node_perm = (array) $lineage_node->json->permissions;
        foreach ($lineage_node_perm as $perm_name => $perm) {
          if (!empty($perm)) {
            $grants[$perm_name] = $perm;
          }
        }
      }
    }

    // TODO: default permissions when nothing is set?
    if (!isset($grants[NODE_ACTION_ADD])) {
      $grants[NODE_ACTION_ADD] = ROLE_ADMIN;
    }
    if (!isset($grants[NODE_ACTION_EDIT])) {
      $grants[NODE_ACTION_EDIT] = ROLE_ADMIN;
    }
    if (!isset($grants[NODE_ACTION_DELETE])) {
      $grants[NODE_ACTION_DELETE] = ROLE_ADMIN;
    }
    if (!isset($grants[NODE_ACTION_VIEW])) {
      $grants[NODE_ACTION_VIEW] = ROLE_ANONYMOUS;
    }

    $this->permissions = $grants;
  }

  /**
   * Set the thumbnail of a node.
   * TODO: maybe not needed, and use setData() is better.
   * @param $thumbnail
   */
  public function setThumbnail($thumbnail) {
    $this->thumbnail = $thumbnail;
  }

  /**
   * Return the thumbnail of a node.
   * @return null
   */
  public function getThumbnail() {
    return $this->thumbnail;
  }

  /**
   * Renders a node edit form.
   * @return mixed
   */
  public function renderMetadataForm() {
    $metadata_form = file_get_contents('core/node/tpl/metadata_form.inc');
    return $metadata_form;
  }

  /**
   * Renders a node delete form.
   * @return mixed
   */
  public function renderDeleteForm() {
    $edit_node = file_get_contents('core/node/tpl/node_delete.inc');
    return $edit_node;
  }

  /**
   * Returns the father of a node (eventually after building it).
   * @return null
   */
  public function getFather() {
    if (!isset($this->father) || $this->father == NULL) {
      $this->buildFather();
    }
    return $this->father;
  }

  /**
   * Builds the father of a node.
   * Usually only done on-request when calling getFather.
   * @return null
   */
  public function buildFather() {
    if (isset($this->father)) {
      return $this->father;
    }
    elseif ($this->exists) {
      $rpath_arr = explode('/', $this->realpath);
      // Look for the node's father.
      if (count($rpath_arr) >= 2) {
        $fatherpath = ($rpath_arr[count($rpath_arr) - 2]);
        $this->father = ($fatherpath == $this->env->host) ? new Node($this->env, 'home') : new Node($this->env, $fatherpath);
      } else {
        $this->father = NodeFactory::current($this->env);
      }
    }
  }

  /**
   * EXPERIMENTAL: find all categories for the node,
   * aka all nodes where it has been included as a symlink (tagged).
   */
  public function getCategories($cat = NULL) {
    $categories = array();
    if ($cat != NULL) {
      $catnode = new Node($this->env, $cat);
      $root = $catnode->realpath;
    }
    else {
      $root = $this->env->dir['docroot'];
    }
    $cmd = 'find -L ' . $root . ' -samefile ' . $this->realpath;
    exec($cmd, $categories_url);
    foreach($categories_url as $cat_url) {
      $exp = explode('/', $cat_url);
      $categories[] = new Node($this->env, $exp[count($exp) - 2]);
    }
    return $categories;
  }

  /**
   * "wrap" node item in html tags allowing inline editing, deleting etc.
   * @param $html
   * @return string
   */
  public function wrap($html) {

    $user = UserFactory::current($this->env);
    $actions = array();

    if ($user->checkAccess($this, NODE_ACTION_EDIT)) {
      $actions[] = '[EDIT:' . $this->getName() . ']';
    }

    if ($user->checkAccess($this, NODE_ACTION_DELETE)) {
      $actions[] = '[DELETE:' . $this->getName() . ']';
    }

    // TODO: it produces a space before the name. Why?
    $wrap = '<div class="node-item" data-rel="' . $this->getName() . '">';
    if (!empty($actions)) {
      $wrap .= '<div class="node-item-actions">' . implode($actions) . '</div>';
    }
    $wrap .= $html;
    $wrap .= '</div>';
    return $wrap;
  }

  /**
   * Check if the Node is forbidden (aka access denied).
   * @return bool
   */
  public function isForbidden() {
    return $this->forbidden;
  }

  /**
   * Check if the Node is available in a certain language.
   * @param $language
   * @return bool
   */
  public function hasTranslation($language) {
    return is_file($this->realpath . '/data_' . $language . '.json');
  }

}
