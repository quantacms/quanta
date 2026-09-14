<?php
namespace Quanta\Common;
date_default_timezone_set('UTC');

/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
#[\AllowDynamicProperties]
class Node extends JSONDataContainer implements Cacheable {
  const NODE_ACTION_ADD = 'node_add';
  const NODE_ACTION_VIEW = 'node_view';
  const NODE_ACTION_EDIT = 'node_edit';
  const NODE_ACTION_DUPLICATE = 'node_duplicate';
  const NODE_ACTION_CHANGE_AUTHOR = 'node_change_author';
  const NODE_ACTION_DELETE = 'node_delete';
  const NODE_ACTION_DELETE_FILE = 'file_delete';
  const NODE_STATUS_DRAFT = 'node-status-draft';
  const NODE_STATUS_PUBLISHED = 'node-status-published';
  const NODE_STATUS_UNPUBLISHED = 'node-status-unpublished';
  const NODE_PERMISSION_INHERIT = 'inherit';
  const NODE_PERMISSION_SELF = 'self';

  const NODE_NEW = '__NEW__';
  
  public $title;
  public $author;
  public $body = NULL;
  public $teaser = NULL;
  public $content = NULL;
  public $exists;
  public $built = FALSE;
  public $permissions;
  public $status;
  public $timestamp;
  public $father = NULL;
  public $thumbnail = NULL;
  protected $lineage = array();
  public $tpl = NULL;
  public $weight = 0;
  public $forbidden = FALSE;

  /**
   * Identity of the state buildContent() last ran against, NULL if it has not
   * run for this object yet.
   *
   * @see Node::load()
   */
  private $loaded_signature = NULL;

  /**
   * Constructs a node object.
   *
   * @param Environment $env
   *   The Environment.
   * @param string $name
   *   The node's name (folder name / path).
   * @param string $father
   *   The Father node of this node.
   * @param string $language
   *   The language of this node.
   */
  public function __construct(&$env, $name, $father = NULL, $language = NULL, $path = NULL) {

    $this->env = $env;
    $this->json = new \stdClass();
 
    // Load node's language.
    $this->setLanguage(!empty($language) ? $language : \Quanta\Common\Localization::LANGUAGE_NEUTRAL);

    // Load node's father (parent folder).
    if ($father != NULL) {
      $this->father = NodeFactory::load($env, $father);
    }

    // TODO: move to nodefactory.
    // Checking if this is not a new node.
    if ($name != self::NODE_NEW) {
	    $this->setName($name);
	    //strtolower($name));
      // TODO: language!
	    // Load node from cache (RAM) if it has been already loaded.

      if (isset($path)) {
        $this->path = $path;
        $this->exists = file_exists($path);
      }

      // If node is not in cache, load it from file system.
      else {
        // TODO: unify path and path.
        $this->path = $this->env->nodePath($this->getName());
        $this->exists = $this->path != NULL;
      }
    } // ...Adding a new node. No values to load.
    else {
      $this->setName(self::NODE_NEW);
      $this->exists = FALSE;
    }

  $this->load();

  }

  /**
   * Load the node from json.
   * TODO: move standard part into JSONDataContainer.
   */
  public function loadJSON() {
    // One call to the node database, which serves this from the daemon's
    // shared-memory segment when it can (no stat, no file read, no
    // json_decode) and off the disk when it cannot. This call site used to
    // carry both — a class_exists()/method_exists() probe, a three-call
    // extension read, and a complete legacy read to fall back to. Only the
    // last of those was ever really about THIS node's behaviour; the rest was
    // deciding which implementation to use, and FilesDb owns that now.
    //
    // 'as' => 'object' (not the default array shape) is the right ask here: it
    // reproduces json_decode's shape all the way down, where the array shape
    // returns the contract's nested ARRAYS. Quanta reads nested documents as
    // objects — see loadPermissions() below ($this->json->permissions->
    // {$permission}) and access.hook.inc — so a plain (object) cast of the
    // array shape would only fix the top level. json_encode() cannot detect
    // that difference; parity has to be checked with var_export().
    //
    // 'at' is load-bearing: this container may have been built from an
    // explicit path (NodeFactory::loadFromRealPath / fastLoadFromRealPath),
    // and the globally-unique NAME may point somewhere else entirely. Passing
    // the path settles that inside the probe that is already holding the
    // record, instead of resolving a second path here to compare against.
    //
    // Re-measure before changing this: loadJSON is the hottest function on
    // admin list pages (~26% of render self-time). The per-implementation
    // numbers are on FilesDbExt::load().
    $language = $this->getLanguage();
    // Quanta's neutral language is a named constant; the database's is the
    // empty string. saveJSON() does the same mapping, and both treat an EMPTY
    // language as neutral — otherwise the two disagree about which file a call
    // names ('data.json' vs 'data_.json').
    $neutral = (empty($language) || $language == \Quanta\Common\Localization::LANGUAGE_NEUTRAL);

    if (empty($this->name) || empty($this->path)) {
      $this->json = new \stdClass;
      return;
    }

    try {
      $loaded = $this->env->db()->load($this->name, array(
        'lang' => $neutral ? NULL : $language,
        'at' => $this->path,
        'as' => 'object',
      ));
    }
    catch (\Quanta\Common\FilesDbException $e) {
      // Load-bearing, not decoration: a torn or invalid document raises
      // CORRUPT_JSON, and the historical behaviour for one is an EMPTY node
      // that still gets its json fields applied — which is what sets the
      // node's status. That is different from a node with no document at all,
      // where the fields are left untouched, so the two cannot be collapsed.
      $this->jsonpath = $this->path . '/data' . ($neutral ? '' : '_' . $language) . '.json';
      $this->json = new \stdClass;
      $this->applyJsonFields();
      return;
    }

    if ($loaded === NULL) {
      // No document in any language tried. A legal state: the node still
      // resolves and still has children.
      $this->json = new \stdClass;
      return;
    }

    // Kept for BC: $this->jsonpath is written here and read nowhere else
    // (saveJSON recomputes its own local $jsonpath). 'lang' is the file that
    // actually answered, so this names it even when the neutral document
    // served a translated request.
    $used = ($loaded['lang'] === '') ? '' : ('_' . $loaded['lang']);
    $this->jsonpath = $this->path . '/data' . $used . '.json';
    $this->json = $loaded['json'];
    $this->applyJsonFields();
  }

  /**
   * Populate the node's object fields from its already-decoded $this->json.
   * Shared by the quanta_db extension read path and the legacy file read.
   */
  private function applyJsonFields() {
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
      $this->setStatus(self::NODE_STATUS_PUBLISHED);
    }
    // Load the node title from json.
    if (isset($this->json->title)) {
      $this->setTitle($this->json->title);
    }
    // Load the node timestamp / craeted time from json.
    if (isset($this->json->timestamp)) {
      $this->setTimestamp($this->json->timestamp);
    }
    // Load the weight of the node.
    if (isset($this->json->weight)) {
      $this->setWeight($this->json->weight);
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
    return ($this->getStatus() == self::NODE_STATUS_PUBLISHED);
  }

  public function cacheTag() {

   return 'node_' . $this->name . '_' . $this->getLanguage();
  }
  /**
   * Update node's json values.
   *
   * @param $ignore array
   *   Which json attributes to ignore.
   */
  public function updateJSON(array $ignore = array()) {
    // Here we generate the json value, using the node object's values.
    $this->json->name = $this->getName();
    $this->json->teaser = $this->getTeaser();
    $this->json->author = $this->getAuthor();
    $this->json->body = $this->getBody();
    $this->json->title = $this->getTitle();
    $this->json->thumbnail = $this->getThumbnail();
    $this->json->timestamp = empty($this->getTimestamp()) ? time() : $this->getTimestamp();
    $this->json->weight = empty($this->getWeight()) ? time() : $this->getWeight();
    $this->json->status = $this->getStatus();
  }

  /**
   * Gets an attribute from the node's json metadata.
   * TODO: better evaluation of JSON data.
   *
   * @param string $attr_name
   *   The attribute to fetch.
   *
   * @return mixed
   *   The JSON attribute.
   */
  public function getAttributeJSON($attr_name, $normalize = TRUE) {
    if (!isset($this->json->{$attr_name})) {
      return NULL;
    }
    $json_attribute = $this->json->{$attr_name};
    // Normalize the content of the json, if the normalize flag is set.
    if ($normalize) {
      if (is_string($json_attribute)) {
        $json_attribute = Api::string_normalize($json_attribute);
      }
      elseif (is_array($json_attribute)) {
        foreach ($json_attribute as $k => $value) {
          $json_attribute[$k] = Api::string_normalize($value);
        }
      }
    }
    return $json_attribute;
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
   * Remove a node's JSON attribute.
   *
   * @param string $attr_name
   *   The JSON attribute name.
   */
  public function removeAttributeJSON($attr_name) {
    unset($this->json->{$attr_name});
  }

  /**
   * Load node with its internal variables.
   *
   * @param bool $force
   *   Rebuild the node's content even if it has already been built against
   *   the same language and path. @see Node::$loaded_signature
   */
  public function load($force = FALSE) {
    // TODO: following code should not be here. Moved from former hook node load.
    // When saving a node, select the pre-created temporary files dir.
    if (!empty($_REQUEST['json']) && ($json = json_decode($_REQUEST['json'])) && isset($json->tmp_files_dir)) {
      $this->setData('tmp_files_dir', array_pop($json->tmp_files_dir->value));
    }
    else {
      $this->setData('tmp_files_dir', $this->getName() . '-' . $this->getData('timestamp'));
    }

    if (!empty($_REQUEST['json']) && ($json = json_decode($_REQUEST['json'])) && isset($json->files_count)) {
      $this->setData('files_count', $json->files_count->value);
    }
   

    //TODO: find a better way to check node existence.
    if ($this->exists) {
      // Build the content only when this object has not already been built
      // against exactly this language and path.
      //
      // Every node that misses the request cache is loaded at least twice and
      // usually three times, all on the SAME object and all but the last with
      // identical state: the constructor ends with $this->load(), then
      // NodeFactory::load() calls $node->load() again, and a node with no
      // document in the requested language gets setLanguage($fallback) and a
      // third call. Only that last call looks at different state; the
      // signature lets it through and stops the duplicate.
      //
      // This is not a cache and holds nothing across objects: a Node still
      // owns its own json, so nothing can alias or leak between nodes. It only
      // declines to redo work THIS object has already done. save() clears the
      // signature, so a write is still followed by a real reload.
      $signature = $this->getLanguage() . "\0" . $this->path;
      if ($force || $this->loaded_signature !== $signature) {
        $this->buildContent();
        $this->loaded_signature = $signature;
      }
    }

    // TODO: what to do when no timestamp has been set?
    if (!$this->exists || ($this->getTimestamp() == NULL)) {
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
    $this->built = TRUE;
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
    // DIR_DIRS is "real subdirectories + symlinked members", which is exactly
    // what the is_dir() filter of the scan this replaced selected.
    return !empty($this->env->db()->children($this->getName(), array(
      'type' => \Quanta\Common\Environment::DIR_DIRS,
      'at' => $this->path,
    )));
  }
  /**
   * Check if the node has been built.
   * @return bool
   */
  public function isBuilt() {
    return $this->built;
  }
  /**
   * Check if the node's folder has a subfolder (subnode).
   *
   * @param $name
   *   The child node's name.
   *
   * @return bool
   *   TRUE if the node has that child.
   */
  public function hasChild($name) {
    // child(), not children(): '_'-prefixed children stay in the answer (the
    // is_dir() this replaced never hid them, and callers ask about names like
    // '_jobs_todo'), and asking about one name is one probe either way, where
    // listing them all is a full scandir() on the implementation that has to
    // read the disk.
    return $this->env->db()->child($this->getName(), $name, array('at' => $this->path));
  }

  /**
   * Checks if the node is the currently viewed one.
   *
   * @return bool
   *   TRUE if the node is the currently viewed one.
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
    // I commented this so that we can save the notes in the description
    // if ($this->getTitle() == '') {
    //   new Message($this->env,
    //     t('Node title can not be empty.'),
    //     \Quanta\Common\Message::MESSAGE_WARNING
    //   );
    //   $valid = FALSE;
    // }
    if (!$author->exists && $author->getName() != \Quanta\Common\User::USER_ANONYMOUS) {
      new Message($this->env,
        t('User !author is not a valid user!', array('!author' => $this->getAuthor())),
        \Quanta\Common\Message::MESSAGE_WARNING
      );
      $valid = FALSE;
    }

    return $valid;
  }

  /**
   * Save this node on the file system.
   */
  public function save() {
    // If path has not been set (i.e. new node) create it based on father node.
    if (empty($this->path)) {
      $this->path = $this->env->nodePath($this->getFather()->getName()) . '/' . $this->getName();
    }

    $vars = array('node' => &$this, 'action' => $this->env->getData('action'));

    // The document on disk is about to change, so whatever load() built is no
    // longer what a reader would find. Dropping the signature keeps "reload
    // after a write" meaning a real reload. @see Node::load()
    $this->loaded_signature = NULL;

    // Run node save hooks.
    $this->env->hook('node_save', $vars);
    // Reload the node JSON.
    $this->updateJSON();
    // Save the node json (excluding some fields such as path.)
    $this->saveJSON(array('name', 'path', 'exists', 'father', 'data'));
    // Clear the node path cache so it's not cached as missing.
    $this->env->nodePath($this->getName(), FALSE, TRUE);
    // Cache the new path to avoid an expensive docroot search later
    Cache::storeNodePath($this->env, $this->path, true);
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
    // TODO: why not using api::stringNormalize?
   if ($this->teaser != NULL) {
	  $teaser = preg_replace('/\[[^>]*\]/', '', strip_tags($this->teaser));
   }
   else {
   	$teaser = '';
   }
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
    return ($this->author == NULL) ? \Quanta\Common\User::USER_ANONYMOUS : $this->author;
  }

  /**
   * Delete this node by adding a __ prefix to the folder.
   */
  public function delete() {
    // The node database owns the move to the trashbin: under the node's lock,
    // with the index told on the ack, where it can, and an `mv -T` where it
    // cannot. Both implementations land the node in the same trashbin root —
    // quanta_db.trashbin_dir is pointed at $env->dir['trashbin'] — so recovery
    // works the way it always did.
    try {
      $deleted = $this->env->db()->delete($this->getName(), array('at' => $this->path));
    }
    catch (\Quanta\Common\FilesDbException $e) {
      new Message($this->env,
        t('Failed to move the file.'),
        \Quanta\Common\Message::MESSAGE_WARNING,
        \Quanta\Common\Message::MESSAGE_TYPE_SCREEN
      );
      return;
    }

    if (!$deleted) {
      new Message($this->env,
        t('Source file does not exist.'),
        \Quanta\Common\Message::MESSAGE_WARNING,
        \Quanta\Common\Message::MESSAGE_TYPE_SCREEN
      );
      return;
    }

    $vars = array('node' => &$this);
    // Run node delete hooks.
    $this->env->hook('node_delete', $vars);
    new Message($this->env,
      t('User deleted this node: !node.', array('!node' => $this->getName())),
      \Quanta\Common\Message::MESSAGE_GENERIC,
      \Quanta\Common\Message::MESSAGE_TYPE_LOG,
      'node'
    );
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
    if (!empty($teaser)) {
      $teaser = strip_tags($teaser);
    }
    $this->teaser = $teaser;
  }

  /**
   * Builds a complete lineage of the node, from its root directory.
   * Useful for breadcrumbs.
   */
  public function buildLineage() {
    if (!empty($this->lineage) || empty($this->path)) {
      return;
    }

    // Explode the full directory of the node to retrieve the relative path.
    $explode_path = explode($this->env->dir['docroot'], $this->path);

    // If count of path elements is <= 1 probably we are in homepage, therefore no lineage available.
    if (count($explode_path) > 1) {
      $fullpath = $explode_path[1];
      $bca = explode('/', $fullpath);
      foreach ($bca as $bread_node) {
        // In the lineage don't include the current node, or empty nodes.
        if ($bread_node == '' || $bread_node == $this->getName()) {
          continue;
        }
        // TODO: use nodefactory without a loop.
        $node = \Quanta\Common\NodeFactory::load($this->env, $bread_node);
        $this->lineage[$node->getName()] = $node;
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
   * Get the weight of the node.
   *
   * @return int
   *   The weight of the Node.
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * Set the weight of the node.
   *
   * @param $weight
   *   The weight of the node.
   */
  public function setWeight($weight) {
    $this->weight = $weight;
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
   * @return string
   *   The rendered node in HTML.
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
      self::NODE_ACTION_ADD,
      self::NODE_ACTION_EDIT,
      self::NODE_ACTION_DELETE,
      self::NODE_ACTION_VIEW,
    );

    foreach ($permissions as $permission) {
      if (empty($this->json->permissions->{$permission}) || $this->json->permissions->{$permission} == self::NODE_PERMISSION_INHERIT) {
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
   * @param $permission
   *   Loads a node permission from its lineage.
   *
   * @return mixed
   *   The calculated permission.
   */
  private function loadPermissionFromLineage($permission) {
    $this->buildLineage();
    // TODO: default permissions when no permission can be found even in lineage.
    $grant = ($permission == self::NODE_ACTION_VIEW) ? \Quanta\Common\User::ROLE_ANONYMOUS : \Quanta\Common\User::ROLE_ADMIN;
    // Navigate the whole tree gathering real permissions on the node.
    $lineage = array_reverse($this->getLineage());
    foreach ($lineage as $lineage_node) {
      // Stop when a suitable parent node with permissions is found.
      if (!empty($lineage_node->json->permissions->{$permission}) && $lineage_node->json->permissions->{$permission} != self::NODE_PERMISSION_INHERIT) {
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
      $rpath_arr = explode('/', $this->path);
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
    // links() is the containers holding a symlink to this node
    // (qdb/docs/api-contract.md §9). The $node-scoped call passes 'in' to
    // limit the sweep to one subtree, which the index cannot express — so that
    // one always goes to the filesystem, and says so rather than being a
    // fallback nobody can see.
    $opts = array('at' => $this->path);
    if ($node != NULL) {
      $catnode = NodeFactory::load($this->env, $node);
      $opts['in'] = $catnode->path;
    }
    $containers = $this->env->db()->links($this->getName(), $opts);

    // links() reports real containers only, never the node's own father, where
    // the `find -L … -samefile` this replaced also matched the node's own
    // directory (with -L a symlink carries the target's inode) and so put the
    // father in the result. Re-added here to keep the answer identical.
    //
    // Taken raw rather than through getFather(), both to avoid building a Node
    // and because buildFather() rewrites the docroot to 'home' while the find
    // result did not.
    $parent = basename(dirname($this->path));
    if ($parent !== '' && $parent !== '.') {
      $containers[] = $parent;
    }

    $categories = array();
    foreach (array_unique($containers) as $container) {
      $categories[] = NodeFactory::load($this->env, $container);
    }
    return $categories;
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
    return $this->name == self::NODE_NEW;
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
    if (empty($language)) {
      // The neutral document is not a translation. The database names it '',
      // and hasLang('') would answer about data.json — where this method has
      // always stat'd 'data_.json', a file no writer produces, and so has
      // always said no. Kept as a semantic rule rather than a stat that only
      // ever fails.
      return FALSE;
    }
    // hasLang(), not langs(): NodeFactory::load() asks this for every node it
    // loads, so it is on the hottest path in the system, and listing every
    // language to look for one would turn a single is_file() into a glob() on
    // the implementation that has to read the disk.
    return $this->env->db()->hasLang($this->getName(), $language, array('at' => $this->path));
  }

}
