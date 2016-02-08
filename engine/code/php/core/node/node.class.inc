<?php
define('NODE_ACTION_VIEW', 'node_view');
define('NODE_ACTION_ADD', 'node_add');
define('NODE_ACTION_EDIT', 'node_edit');
define('NODE_ACTION_DELETE', 'node_delete');
define('NODE_ACTION_DELETE_FILE', 'file_delete');
date_default_timezone_set('UTC');
/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class Node extends DataContainer {
  public $name;
  public $title;
  public $body = NULL;
  public $content = NULL;
  public $content_raw = NULL;
  private $context;
  public $path;
  public $fullpath;
  public $realpath;
  public $exists;
  public $permissions;
  public $timestamp;
  public $empty;
  public $father = NULL;
  public $thumbnail = NULL;
  private $json;
  private $lineage = array();
  public $tmp_files_dir = NULL;

  /**
   * Constructs a node object.
   * @param $env
   * @param $name
   * @param null $father
   */
  public function __construct(&$env, $name, $father = NULL) {
    $this->env = $env;
    $this->setName(strtolower($name));
    if ($father != NULL) {
      $this->father = $father;
    }
    $this->path = $this->env->dir['wiki'] . '/' . $this->getName();
    $this->exists = is_link($this->path);
    // Todo: make even safer.
    if ($this->exists || $father != NULL) {
      $this->load();
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
    // Load node from cache (RAM) if it has been already loaded.
    if ($cached = Cache::get($this->env, 'node', $this->name)) {
      foreach (get_object_vars($cached) as $key => $value) {
        $this->$key = $value;
      }
      $this->env->hook('node_load_cache', array(
        'node' => &$this,
      ));
    }

      // Create symlink - wiki redirect.
    if ($this->father != NULL) {
      $this->realpath = $this->father->realpath . '/' . $this->getName();
    }
    else {
      $this->realpath = readlink($this->path);
    }
    $this->fullpath = str_replace($this->env->dir['docroot'], '', $this->realpath);

    $this->env->hook('node_load', array(
      'node' => &$this,
    ));

    if ($this->content == NULL && $this->exists) {
      $this->buildContent();
    }
    if ($this->exists) {
      $this->setTimestamp(filemtime($this->realpath));
    }
    else {
      $this->setTimestamp(time());
    }

  }

  /**
   * Builds node content.
   */
  public function buildContent() {
    // Create data.html if it doesn't exist.
    if (is_file($this->path . '/data.html')) {
      $this->setContent(file_get_contents($this->path . '/data.html'));
      $this->setBody(preg_replace('!<\s*(h1).*?>((.*?)</\1>)?!is', '', $this->getContent()));
      // TODO: needed to remove the h1 from the body... to double check.
      $this->content_raw = $this->content;
      $this->setTitle(parsetag($this->content, 'h1'));
      $this->empty = FALSE;
    } else {
      $this->setTitle($this->name);
      $this->empty = TRUE;
    }

    if (is_file($this->path . '/data.json')) {
      $this->json = json_decode(file_get_contents($this->path . '/data.json'));
      $this->setTimestamp($this->json->timestamp);
      if (isset($this->json->thumbnail)) {
        $this->setThumbnail($this->json->thumbnail);
      }
    }

    $this->env->hook('node_build', array(
      'node' => &$this,
    ));
    Cache::set($this->env, 'node', $this->name, $this);
  }

  /**
   * Check if the node is empty.
   * @return mixed
   */
  public function isEmpty() {
    return $this->empty;
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
    return is_dir($this->realpath . '/' . $name);
  }

  /**
   * Checks if the node is the currently viewed one.
   * @return bool
   */
  public function isCurrent() {
    return ($this->name == $this->env->getRequestedPath());
  }

  /**
   * Gets the current viewed node.
   * @return bool
   */
  public static function current($env) {
    return new Node($env, $env->getRequestedPath());
  }

  /**
   * Request to perform an action on the node. Check permissions and
   * execute the action accordingly.
   * @param $env Environment
   * @param $action string
   * @return string
   */
  public static function requestAction($env, $action, $nodedata) {
    $response = new StdClass();

    $user = User::current($env);
    // TODO: refactor this stuff.

    // When user didn't enter a path...
    if (trim($nodedata['edit-path']) == '') {
      $path = $env->getCandidatePath($nodedata['edit-title']);
    }
    else {
      $path = $nodedata['edit-path'];
    }

    $node = new Node($env, $path, ($action == NODE_ACTION_ADD) ? new Node($env, $nodedata['edit-father']) : NULL);
    $node->context = $action;
    switch ($action) {
      case NODE_ACTION_ADD:
      case NODE_ACTION_EDIT:
        // TODO complete validation code.
        if (isset($nodedata['edit-thumbnail'])) {
          $node->setThumbnail($nodedata['edit-thumbnail']);
        }
        $datetime = strtotime($nodedata['edit-date'] . ' ' . $nodedata['edit-time']);
        $has_access = ($user->checkAccess(($action == NODE_ACTION_ADD) ? $node->father : $node, $action));
        if ($has_access) {
          $node->setTitle($nodedata['edit-title']);
          $node->setBody($nodedata['edit-content']);
          $node->setContent('<h1>' . $node->getTitle() . '</h1>' . $node->getBody());
          $node->setTimestamp($datetime > 0 ? $datetime : time());
          $node->tmp_files_dir = $nodedata['tmp_files_dir'];
          if ($node->validate()) {
            $node->save();
            $env->hook('node_save', array(
              'node' => &$node,
              'data' => $nodedata
            ));
            $response->redirect = '/' . $node->getName() . '/';
          }
          else {
            // TODO: make this good.
            $response->errors = Message::burnMessages();
          }
        }
        else {
          $response->redirect = '/403';
        }
        break;

      case NODE_ACTION_DELETE:
        $has_access = ($user->checkAccess($node, NODE_ACTION_DELETE));
        if ($has_access) {
          $node->delete();
          new Message($node->env, $node->getName() . ' was deleted correctly');
          $response->redirect = '/' . $node->getFather()->getName() . '/';
        }
        break;
    }
    $response_json = json_encode($response);

    return $response_json;
  }

  /**
   * Save this node on the file system.
   */
  public function validate() {
    $valid = FALSE;
    if ($this->getTitle() == '') {
      new Message($this->env, 'Node title can not be empty.', MESSAGE_WARNING);
    }
    else {
      $valid = TRUE;
    }

    return $valid;
  }


  /**
   * Save this node on the file system.
   */
  public function save() {
    $this->env->hook('node_preinsert');
    $this->saveHTML();
    $this->saveJSON();
    $this->saveFiles();
    $this->env->hook('node_insert');
  }


  public function saveFiles() {
    // TODO: refactor.
    $tmp_dir = $this->env->dir['tmp_files'] . '/' . $this->tmp_files_dir;
    if (is_dir($tmp_dir)) {
      $tmp_files = $this->env->scanDirectory($tmp_dir);
      foreach ($tmp_files as $file) {
        $fpath = $tmp_dir . '/' . $file;
        $fileobj = new File($this->env, $fpath, $this);
        if (!$fileobj->isPublic()) {
          new Message($this->env, 'The file <b>' . $file . '</b> has an invalid name. Please use a different name.', MESSAGE_WARNING);
        }
        elseif (is_file($fpath)) {
          copy($fpath, $this->realpath . '/' . $file);
          unlink($fpath);
        }
      }
      rmdir($tmp_dir);
    }
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

  public function getTitle() {
    return $this->title;
  }

  public function setTitle($title) {
    $this->title = htmlspecialchars($title);
  }

  public function getContent() {
    // TODO: < and > break in the body. Urgent fix to do.
    return str_replace('< ', '&lt; ', str_replace('> ', '&gt; ', $this->content));
  }

  public function getRawContent() {
    return $this->content_raw;
  }

  /**
   * Save the JSON dump of the node
   */
  public function saveJSON() {
    $node = clone $this;
    if (!is_dir($node->realpath)) {
      mkdir($node->realpath) or die('cannot create dir: ' . $node->realpath);
    }
    unset($node->request_json);
    unset($node->env);
    $jsonpath = $node->realpath . '/data.json';
    $fh = fopen($jsonpath, 'w+');
    fwrite($fh, json_encode($node));
    fclose($fh);
  }

  /**
   * Delete this node and disable it by adding __ to the folder.
   */
  public function delete() {
    $np = explode('/', $this->realpath);
    $rmname = '__' . $np[count($np) - 1];
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

  public function setContent($content) {
    $this->content = $content;
  }

  public function buildLineage() {
    if ($this->isHome()) {
      return;
    }
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
    $content = $this->getContent();
    return str_replace(chr(13), '', $content);
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
   * Load and construct permissions for this node.
   */
  private function loadPermissions() {
    $grants = array();
    // Load global permissions.
    $check = array_reverse(explode('/', $this->realpath));
    // Load specific permissions.
    foreach ($check as $path) {
      $n = new Node($this->env, $path);
      $access_path = ($n->exists ? $n->realpath : $this->env->dir['docroot']);
      if (is_file($access_path . '/.access')) {
        $access = file($access_path . '/.access');
        foreach ($access as $grant_item) {
          $grants_item = explode(':', $grant_item);
          if (!isset($grants[$grants_item[0]])) {
            $grants[$grants_item[0]] = $grants_item[1];
          }
        }
      }
      if (!($n->exists)) {
        break;
      }
    }
    $this->permissions = $grants;
  }

  /**
   * Check the uploads being made to a node.
   */
  public function checkUploads() {
    $allowed = array(
      'png',
      'jpg',
      'jpeg',
      'gif',
      'zip',
      'pdf',
      'mov',
      'rtf',
      'doc',
      'docx',
      'gz',
      'mp3',
      'mp4',
      'mov',
      'm4a',
      'txt',
      'xls',
      'xlsx'
    );

    $upload_dir = ($this->env->dir['tmp_files'] . '/' . $_REQUEST['tmp_files_dir']);
    if (!is_dir($upload_dir)) {
      mkdir($upload_dir);
    }

    if (isset($_FILES['upl']) && $_FILES['upl']['error'] == 0) {
      $extension = pathinfo($_FILES['upl']['name'], PATHINFO_EXTENSION);
      if (!in_array(strtolower($extension), $allowed)) {
        echo '{"status":"error"}';
        exit;
      }
      if (move_uploaded_file($_FILES['upl']['tmp_name'], $upload_dir . '/' . strip_tags($_FILES['upl']['name']))) {
        echo '{"status":"success"}';
        exit;
      }
    }
    echo '{"status":"error"}';
    exit;
  }

  /**
   * Request the deletion of a file.
   * @param $file File
   */
  public function deleteFile($file) {
    unlink($this->realpath . '/' . $file);
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
   * @param $context
   * @return mixed
   */
  public function renderEditForm($context) {
    $this->context = $context;
    $edit_node = $this->wikize(file_get_contents('core/node/tpl/node_edit.inc'));
    return $edit_node;
  }


  /**
   * Renders a node delete form.
   * @param $context
   * @return mixed
   */
  public function renderDeleteForm($context) {
    $this->context = $context;
    $edit_node = $this->wikize(file_get_contents('core/node/tpl/node_delete.inc'));
    return $edit_node;
  }

  /**
   * Substitute node tokens in a template.
   *
   * @param $edit_node
   * @return mixed
   */
  private function wikize($edit_node) {
    preg_match_all('/\[NODE\:(.*)\]/', $edit_node, $m);
    foreach ($m[1] as $k => $nodepart) {
      switch ($nodepart) {

        case 'action':
          $part = $this->context;
          break;
        case 'father':
          $part = $this->getFather()->getName();
          break;
        case 'title':
          $part = $this->getTitle();
          break;

        case 'body':
          $part = unwikize($this->env, $this->getBody());
          break;

        case 'thumbnail':
          $part = $this->getThumbnail();
          break;

        case 'thumbnail-img':
          $thumb = new Image($this->env, $this->getThumbnail(), $this);
          if ($thumb->exists) {
            $part = $thumb->render();
          }
          else {
            $part = 'No thumbnail uploaded';
          }
          break;

        case 'time':
          $part = $this->getTime();
          break;

        case 'date':
          $part = $this->getDate();
          break;

        case 'name':
          $part = $this->getName();
          break;

        case 'filelist':
          $fl = new FileList($this->env, $this->context == NODE_ACTION_ADD ? NULL : $this->getName(), 'file_admin');
          $part = $fl->render();
          break;

        default:
          // The item in [NODE:item] is unknown.
          $part = 'undefined:' . $nodepart;
          break;
      }

      $edit_node = str_replace($m[0][$k], $part, $edit_node);
    }
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
    else {
      $rpath_arr = explode('/', $this->realpath);
      $fatherpath = ($rpath_arr[count($rpath_arr) - 2]);
      $this->father = ($fatherpath == $this->env->host) ? new Node($this->env, 'home') : new Node($this->env, $fatherpath);
    }
  }

  /**
   * Renders a file upload form for a node.
   * @param $context
   * @return mixed
   */
  public function renderUploadForm($context) {
    $this->context = $context;
    $this->tmp_files_dir = $this->getName() . '-' . time() . '-' . rand(0, 1000);
    $up_form = $this->wikize(file_get_contents('core/node/tpl/upload_form.inc'));
    return $up_form;
  }
}
