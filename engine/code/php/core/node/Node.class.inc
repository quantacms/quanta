<?php
define('NODE_ACTION_VIEW', 'node_view');
define('NODE_ACTION_ADD', 'node_add');
define('NODE_ACTION_EDIT', 'node_edit');
define('NODE_ACTION_DELETE', 'node_delete');
define('NODE_ACTION_DELETE_FILE', 'file_delete');
define('NODE_NEW', '__NEW__');

date_default_timezone_set('UTC');
/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class Node extends JSONDataContainer {
  public $name;
  public $title;
  public $author;
  public $body = NULL;
  public $teaser = NULL;
  public $content = NULL;
  public $exists;
  public $permissions;
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
  public function __construct(&$env, $name, $father = NULL, $tpl_node = NULL) {
    $this->env = $env;
    $this->json = new stdClass();
    if ($father != NULL) {
      $this->father = NodeFactory::load($env, $father);
    }

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
      } else {
        $result = $this->env->nodePath($this->getName());

        // TODO: unify path and realpath.
        $this->path = $result;
        $this->realpath = $result;
        $this->exists = $result != NULL;
      }
    } else {
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
   */
  public function loadJSON() {
    if (is_file($this->path . '/data.json')) {
      $this->json = (object) json_decode(file_get_contents($this->realpath . '/data.json'));
    } else {
      $this->json = new StdClass;
      // No json file. Empty node, or incomplete / unknown.
      return;
    }
    if (isset($this->json->teaser)) {
      $this->setTeaser($this->json->teaser);
    }
    if (isset($this->json->author)) {
      $this->setAuthor($this->json->author);
    }
    if (isset($this->json->body)) {
      $this->setBody($this->json->body);
    }
    if (isset($this->json->title)) {
      $this->setTitle($this->json->title);
    }
    if (isset($this->json->timestamp)) {
      $this->setTimestamp($this->json->timestamp);
    }
    if (isset($this->json->thumbnail)) {
      $this->setThumbnail($this->json->thumbnail);
    }
  }

  /**
   * Unpublished nodes' folders will always start with _.
   */
  public function isPublished() {
    return (substr($this->name, 0, 1) != '_');
  }

  /**
   * Update node's json values.
   */
  public function updateJSON($ignore = array()) {
    $this->json->name = $this->getName();
    $this->json->teaser = $this->getTeaser();
    $this->json->author = $this->getAuthor();
    $this->json->body = $this->getBody();
    $this->json->title = $this->getTitle();
    $this->json->thumbnail = $this->getThumbnail();
    $this->json->timestamp = $this->getTimestamp();
  }



  //*** TEMPORARY FIX ***
  public function getAttributeJSON($attr_name){
    if (isset($this->json->$attr_name)) {
      return $this->json->$attr_name;
    }
  }


  /**
   * Gets the name (equal to folder name) of the node.
   * @return mixed
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Sets the name (equal to folder name) of the node.
   * @param $name
   */
  public function setName($name) {
    $this->name = $name;
  }

  /**
   * Load node with its variables internally.
   */
  public function load() {

    $this->fullpath = str_replace($this->env->dir['docroot'], '', $this->realpath);

    $this->env->hook('node_load', array(
      'node' => &$this,
    ));

    if ($this->content == NULL && $this->exists) {
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

    // if data.html is present load data from data.html
    // TODO: at some point loading data from data.html should be discontinued...
    if (is_file($this->path . '/data.html')) {
      $html_content = file_get_contents($this->path . '/data.html');
      $this->setBody(preg_replace('!<\s*(h1).*?>((.*?)</\1>)?!is', '', $html_content));
      $this->setContent($html_content);
      $this->setTitle(parsetag($this->content, 'h1'));
    }
    else {
      $this->setTitle($this->name);
    }

    $this->env->hook('node_build', array(
      'node' => &$this,
    ));

    Cache::set($this->env, 'node', $this->name, $this);
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
    $this->saveHTML();
    $this->env->hook('node_insert', array(
      'node' => &$this,
    ));
  }

  /**
   * Save this node on a data.html file.
   */
  public function saveHTML() {
    // Add-edit file.

    if (!is_dir($this->realpath)) {
      mkdir($this->realpath) or die('cannot create dir: ' . $this->realpath);
    }
    $htmlpath = $this->realpath . '/data.html';
    $fh = fopen($htmlpath, 'w+');
    fwrite($fh, $this->getContent()) or die('Cannot write on file: ' . $this->realpath);
    fclose($fh);

    new Message($this->env, 'user edited this file: ' . $this->getName(), MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');
  }

  public function getBody() {
    return $this->body;
  }

  public function setBody($body) {
    $this->body = $body;
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
    else {
      return preg_replace('/\[[^>]*\]/', '', (substr(strip_tags($this->getBody()), 0, $maxlength)));
    }
  }

  public function getTitle() {
    return $this->title;
  }

  public function setTitle($title) {
    $this->title = htmlspecialchars($title);
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
    $bca = explode('/', $this->fullpath);
    foreach ($bca as $bread_node) {
      if ($bread_node == '') {
        continue;
      }
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
    // Use another node as a template.
    if (isset($this->tpl_node)) {
      $tpl_node = new Node($this->env, $this->tpl_node);
      if ($user->checkAccess($tpl_node, NODE_ACTION_VIEW)) {
        $this->tpl = $tpl_node->getBody();
      } else {
        print "Error loading template: access denied";
        die();
      }
    }

    else {
      $this->tpl = $this->getContent();
      $tpl = array();
      $tpl_level = 0;

      if (is_file($this->realpath . '/tpl.html')) {
        $this->tpl = file_get_contents($this->realpath . '/tpl.html');
      } else {
        foreach ($this->lineage as $lineage) {

          $tpl_level++;
          // a template in the same directory has always priority.
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
   * Check if the node is forbidden (aka access denied).
   * @return bool
   */
  public function isForbidden() {
    return $this->forbidden;
  }

}
