<?php
define('NODE_ACTION_VIEW', 'node_view');
define('NODE_ACTION_ADD', 'node_add');
define('NODE_ACTION_EDIT', 'node_edit');
define('NODE_ACTION_EDIT_TPL', 'node_edit_tpl');
define('NODE_ACTION_DELETE', 'node_delete');
define('NODE_ACTION_DELETE_FILE', 'file_delete');
define('NODE_STATUS_DRAFT', 'node_status_draft');
define('NODE_STATUS_PUBLISHED', 'node_status_published');
define('NODE_STATUS_UNPUBLISHED', 'node_status_unpublished');
define('NODE_PERMISSION_INHERIT', 'inherit');
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
  public $tpl = NULL;
  public $forbidden = FALSE;

  /**
   * Constructs a node object.
   * @param Environment $env
   * @param string $name
   * @param string $father
   * @param string $language
   */
  public function __construct(&$env, $name, $father = NULL, $language = NULL) {

    $this->env = $env;
    $this->json = new stdClass();

    // Load node's language.
    $this->setLanguage(!empty($language) ? $language : Localization::getLanguage($this->env));

    // Load node's father (parent folder).
    if ($father != NULL) {
      $this->father = NodeFactory::load($env, $father);
    }

    // TODO: move to nodefactory.
    // Checking if this is not a new node.
    if ($name != NODE_NEW) {
      $this->setName(strtolower($name));

      // TODO: language!
      // Load node from cache (RAM) if it has been already loaded.
      if ($cached = Cache::get($this->env, 'node', $this->name)) {
        foreach (get_object_vars($cached) as $key => $value) {
          $this->{$key} = $value;
        }

        $vars = array('node' => &$this);
        $this->env->hook('node_load_cache', $vars);
        $this->exists = TRUE;
      } // If node is not in cache, load it from file system.
      else {
        $result = $this->env->nodePath($this->getName());
        // TODO: unify path and realpath.
        $this->path = $result;
        $this->realpath = $result;
        $this->exists = $result != NULL;
      }

    } // ...Adding a new node. No values to load.
    else {
      $this->setName(NODE_NEW);
      $this->exists = FALSE;
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
      $this->jsonpath = ($this->path . '/data_' . $this->getLanguage() . '.json');
    } // Look for a language neutral version.
    elseif (is_file($this->path . '/data.json')) {
      $this->jsonpath = ($this->path . '/data.json');
    } // Impossible to load json. Error or empty Node.
    else {
      $this->json = new StdClass;
      return;
    }

    $this->json = (object)json_decode(file_get_contents($this->jsonpath));


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
    } // Use published as a fallback. TODO: is this correct?
    else {
      $this->setStatus(NODE_STATUS_UNPUBLISHED);
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
   *
   * @param $ignore array
   *   Which json attributes to ignore.
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

  /**
   * Temporary function.
   * TODO: better evaluation of JSON.
   *
   * @param $attr_name
   * @return null
   */
  public function getAttributeJSON($attr_name, $normalize = TRUE) {

    if (!isset($this->json->{$attr_name})) {
      return NULL;
    }

    $ret = $this->json->{$attr_name};
    if ($normalize) {
      if (is_string($ret)) {
        $ret = string_normalize($ret);
      }
      elseif (is_array($ret)) {
        foreach ($ret as $k => $value) {
          $ret[$k] = string_normalize($value);
        }
      }
    }

    return $ret;
  }


  /**
   * Set up a node's JSON attribute.
   *
   * @param string $attr_name
   *   The JSON attribute name.
   * @param string $attr_value
   *   The JSON attribute value.
   */
  public function setAttributeJSON($attr_name, $attr_value) {
    $this->json->{$attr_name} = $attr_value;
  }

  /**
   * Load node with its variables internally.
   */
  public function load() {
    $vars = array('node' => &$this);
    $this->env->hook('node_load', $vars);

    //TODO: find a better way to check node existence
    if (!isset($this->json->timestamp) && $this->exists) {

      $this->buildContent();
    }
    if ($this->exists && ($this->getTimestamp() == NULL)) {
      // TODO: what to do when no timestamp has been set?
      $this->setTimestamp(time());
    }
  }

  /**
   * Builds node content.
   */
  public function buildContent() {
    // Load data from JSON file if possible.
    $this->loadJSON();
    $vars = array('node' => &$this);

    $this->env->hook('node_build', $vars);
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
   *
   * @return bool
   */
  public function validate() {
    $valid = TRUE;
    $author = new User($this->env, $this->getAuthor());

    if ($this->getTitle() == '') {
      new Message($this->env, 'Node title can not be empty.', MESSAGE_WARNING);
      $valid = FALSE;
    }
    if (!$author->exists && $author->getName() != USER_ANONYMOUS) {
      new Message($this->env, 'User ' . $this->getAuthor() . ' is not a valid user!', MESSAGE_WARNING);
      $valid = FALSE;
    }

    return $valid;
  }

  /**
   * Save this node on the file system.
   */
  public function save() {
    // If realpath has not been set (i.e. new node) create it based on father node.
    if (empty($this->realpath)) {
      $this->realpath = $this->getFather()->realpath . '/' . $this->getName();
    }
    $vars = array('node' => &$this);

    // Run node save hooks.
    $this->env->hook('node_save', $vars);
    // Reload the node JSON.
    $this->updateJSON();
    // Save the node json (excluding some fields such as path.)
    $this->saveJSON(array('path', 'exists', 'realpath', 'father', 'data'));
    $this->env->hook('node_after_save', $vars);
  }

  /**
   * Returns the body of a node.
   *
   * @return string
   */
  public function getBody() {
    return $this->body;
  }

  /**
   * Sets the body of a node.
   *
   * @param $body
   *   The body of the node.
   */
  public function setBody($body) {
    $this->body = $body;
  }

  /**
   * Gets the status of a node.
   *
   * @return string
   *   The node status.
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * Sets the status of a node.
   *
   * @param string $status
   *   The node status.
   */
  public function setStatus($status) {
    $this->status = $status;
  }

  /**
   * Returns the teaser of the node. Normalized and with tags excluded.
   *
   * @return string
   *   The node teaser.
   */
  public function getTeaser() {
    $teaser = (strlen(trim($this->teaser)) > 0) ? preg_replace('/\[[^>]*\]/', '', strip_tags($this->teaser)) : NULL;
    return $teaser;
  }

  /**
   * Return the node's title.
   *
   * @return string
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

  /**
   * Sets the author of a node.
   * @param $author
   */
  public function setAuthor($author) {
    $this->author = $author;
  }

  /**
   * Gets the author of a node.
   *
   * @return string
   *   The node's author.
   */
  public function getAuthor() {
    return ($this->author == NULL) ? USER_ANONYMOUS : $this->author;
  }

  /**
   * Delete this node by adding a __ prefix to the folder.
   */
  public function delete() {
    $np = explode('/', $this->realpath);
    $rmname = '__' . $np[count($np) - 1] . '_' . time();
    $np[count($np) - 1] = $rmname;
    // Delete file is indeed not an immediate deletion: it adds __ to folder name.
    // This is useful in order to recover a node that was accidentally deleted.
    //unlink($this->path);
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
   *
   * @param string $teaser
   *   The teaser.
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
    // Homepage should not have a lineage. TODO: correct?
    if ($this->isHome()) {
      return;
    }

    $fullpath = str_replace($this->env->dir['docroot'], '', $this->realpath);
    $bca = explode('/', $fullpath);
    foreach ($bca as $bread_node) {
      if ($bread_node == '') {
        continue;
      }
      // TODO: use nodefactory without a loop.
      $n = new Node($this->env, $bread_node);
      if (!$n->exists) {
        continue;
      }
      $this->lineage[] = $n;
    }
  }

  /**
   * Generate the template for displaying the node.
   * Eventually node will use tpl.html files (with dashed depth).
   *
   * TODO: separate template in another object.
   */
  public function buildTemplate() {

    // The node's default renderis is its content.

    $tpl = array();
    $tpl_level = 0;

    // a template in the same directory has always priority.
    if (is_file($this->realpath . '/tpl.html')) {
      $this->setData('tpl_file', $this->realpath . '/tpl.html');
    }
    else {
      foreach ($this->lineage as $lineage) {

        $tpl_level++;

        // If tpl^.html exists - template applies to all sublevels of the node.
        if (is_file($lineage->path . '/tpl^.html')) {
          $this->setData('tpl_file', $lineage->path . '/tpl^.html');
        }
        else {
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
      }

      // check if there is a sub-level template.
      if (isset($tpl[$tpl_level])) {
        $this->setData('tpl_file', $tpl[$tpl_level]);
      }
    }

    // Set the template.
    $this->tpl = (!empty($this->getData('tpl_file'))) ? file_get_contents($this->getData('tpl_file')) : $this->getBody();

  }

  /**
   * Check if current node is the homepage node.
   * @return bool
   */
  public function isHome() {
    return $this->name == 'home';
  }

  /**
   * Get the lineage of a node.
   *
   * @return array
   */
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
    return date('H:i', $this->getTimestamp());
  }

  /**
   * Render the node.
   *
   * @return
   *   The rendered node.
   */
  public function render() {
    return $this->tpl;
  }

  /**
   * Get all the permissions for this node.
   *
   * @return array
   *   The permissions of the node.
   */
  public function getPermissions() {
    if (empty($this->permissions)) {
      $this->loadPermissions();
    }
    return $this->permissions;
  }

  /**
   * Get a specific permission for this node.
   *
   * @param $perm
   *   The permission.
   *
   * @return array
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
    $permissions = array(
      NODE_ACTION_ADD,
      NODE_ACTION_EDIT,
      NODE_ACTION_DELETE,
      NODE_ACTION_VIEW,
    );
    foreach ($permissions as $permission) {
      if (empty($this->json->permissions->{$permission}) || $this->json->permissions->{$permission} == NODE_PERMISSION_INHERIT) {
        $grants[$permission] = $this->loadPermissionFromLineage($permission);
      }
      else {
        $grants[$permission] = $this->json->permissions->{$permission};
      }
    }
    $this->permissions = $grants;
  }

  /**
   * TODO: move in access module.
   * Attempting finding permissions for this node using its lineage.
   */
  private function loadPermissionFromLineage($permission) {
    $this->buildLineage();
    // TODO: default permissions when no permission can be found even in lineage.
    $grant = ($permission == NODE_ACTION_VIEW) ? ROLE_ANONYMOUS : ROLE_ADMIN;
    // Navigate the whole tree gathering real permissions on the node.
    $lineage = array_reverse($this->getLineage());
    foreach ($lineage as $lineage_node) {
      // Stop when a suitable parent node with permissions is found.
      if (!empty($lineage_node->json->permissions->{$permission}) && $lineage_node->json->permissions->{$permission} != NODE_PERMISSION_INHERIT) {
        $grant = $lineage_node->json->permissions->{$permission};
        break;
      }
    }

    return $grant;
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
   * Return the temporary files upload directory of a node.
   * @return null
   */
  public function getTmpFilesDir() {
    return $this->getData('tmp_files_dir');
  }

  /**
   * Renders a node edit form.
   * @return mixed
   */
  public function renderMetadataForm() {
    $metadata_form = file_get_contents($this->env->getModulePath('node') . '/tpl/metadata_form.inc');
    return $metadata_form;
  }

  /**
   * Renders a node delete form.
   * @return mixed
   */
  public function renderDeleteForm() {
    $edit_node = file_get_contents($this->env->getModulePath('node') . '/tpl/node_delete.inc');
    return $edit_node;
  }

  /**
   * Returns the father of a node (eventually after building it).
   * @return Node
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
   *
   * @return Node
   *   The Father of the node.
   */
  public function buildFather() {
    if (!isset($this->father) && $this->exists) {
      $rpath_arr = explode('/', $this->realpath);
      // Look for the node's father.
      if (count($rpath_arr) >= 2) {
        $fatherpath = ($rpath_arr[count($rpath_arr) - 2]);
        $this->father = ($fatherpath == $this->env->host) ? new Node($this->env, 'home') : new Node($this->env, $fatherpath);
      }
      else {
        $this->father = NodeFactory::current($this->env);
      }
    }
    return $this->father;
  }

  /**
   * EXPERIMENTAL: find all categories for the node,
   * aka all nodes where it has been included as a symlink (tagged).
   *
   * TODO: this function should be static and not be placed here.
   *
   * @param Node $node
   *  The node.
   *
   * @return array
   *   All the Nodes containing a symlink to the node.
   */
  public function getCategories($node = NULL) {
    $categories = array();
    if ($node != NULL) {
      $catnode = NodeFactory::load($this->env, $node);
      $root = $catnode->realpath;
    }
    else {
      $root = $this->env->dir['docroot'];
    }
    // Run a find command to search for all symlinks refering to this node.
    $cmd = 'find -L ' . $root . ' -samefile ' . $this->realpath;
    exec($cmd, $categories_url);

    // Build the nodes that contain the symlink.
    foreach ($categories_url as $cat_url) {
      $exp = explode('/', $cat_url);
      $categories[] = NodeFactory::load($this->env, $exp[count($exp) - 2]);
    }
    return $categories;
  }

  /**
   * "wrap" a Node block in html tags allowing inline editing, deleting etc.
   *
   * @param string $html
   *   The initial html.
   *
   * @return string
   *   The wrapped html.
   */
  public function wrap($html) {

    $actions = array();
    // If user can edit the node, add an edit button.
    if (NodeAccess::check($this->env, NODE_ACTION_EDIT, array('node' => $this))) {
      $actions[] = '[EDIT:' . $this->getName() . ']';
    }
    // If user can delete the node, add a delete button.
    if (NodeAccess::check($this->env, NODE_ACTION_DELETE, array('node' => $this))) {
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
   *
   * @return bool
   *   True if this is a forbidden Node that can't be accessed.
   */
  public function isForbidden() {
    return $this->forbidden;
  }

  /**
   * Check if the Node is new (yet to be created).
   *
   * @return bool
   *   True if this is a new node being created.
   */
  public function isNew() {
    return $this->name == NODE_NEW;
  }

  /**
   * Check if the Node is available in a certain language.
   *
   * @param $language
   *   The language for which to check existing translation.
   *
   * @return bool
   *   True if the translation exists in that language.
   */
  public function hasTranslation($language) {
    return is_file($this->realpath . '/data_' . $language . '.json');
  }

}
