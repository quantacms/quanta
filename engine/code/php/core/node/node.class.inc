<?php
define('NODE_ACTION_VIEW', 'node_view');
define('NODE_ACTION_ADD', 'node_add');
define('NODE_ACTION_EDIT', 'node_edit');
define('NODE_ACTION_DELETE', 'node_delete');
define('NODE_ACTION_DELETE_FILE', 'file_delete');

/**
 * Class Node
 * This class represents a Node (corrisponding to a folder in the file system).
 * This is the core of the engine.
 */
class Node {
  public $env;
  public $name;
  public $section;
  public $content = NULL;
  public $path;
  public $fullpath;
  public $realpath;
  public $exists;
  public $title;
  public $permissions;
  public $timestamp;
  private $json;
  private $lineage = array();
  public $father = NULL;

  // Construct the Node.
  public function __construct(&$env, $name, $father = NULL) {
    $this->env = $env;
    $this->setName(strtolower($name));
    if ($father != NULL) {
      $this->father = $father;
    }
    $this->path = $this->env->dir['wiki'] . '/' . $this->getName();

    $this->exists = is_link($this->path);

    if ($this->exists || $father != NULL) {
      $this->load();
    }

  }

  /**
   * Load node variables internally.
   */
  public function load() {
    // Create symlink - wiki redirect.
    if ($this->father != NULL) {
      $fatherpath = $this->env->dir['wiki'] . '/' . $this->father;
      $this->realpath = readlink($fatherpath) . '/' . $this->getName();
    }
    else {
      $this->realpath = readlink($this->path);
      $rpath_arr = explode('/', $this->realpath);
      $father = ($rpath_arr[count($rpath_arr) - 2]);
      $this->father = ($father == $this->env->host) ? 'home' : $father;
    }
    $this->fullpath = str_replace($this->env->dir['docroot'], '', $this->realpath);
    if ($this->content == NULL) {
      $this->buildContent();
    }
    $this->setTitle(parsetag($this->content, 'h1'));
    if ($this->exists) {
      $this->setTimestamp(filemtime($this->realpath));
    } else {

        $this->setTimestamp(time());
      }

  }

  public function isHome() {
    return $this->name == 'home';
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

  public function getLineage() {
    return $this->lineage;
  }

  public function getTitle() {
    return $this->title;
  }

  public function getName() {
    return $this->name;
  }

  public function setName($name) {
    $this->name = $name;
  }

  public function setSection($section) {
    $this->section = $section;
  }

  public function buildContent() {
    // Build HTML file if it exists, or throw error instead.
    if (!is_dir($this->path)) {
      $this->content = '<h1>404: Page not found</h1><br />Uh oh :( This file or this page doesn\'t exist:<br>' . $this->path . '<br>[home:Go Home!]';
    }
    else {
      // Create data.html if it doesn't exist.
      if (!is_file($this->path . '/data.html')) {
        $fh = fopen($this->path . '/data.html', 'w+');
        fwrite($fh, '<h1>' . $this->name . '</h1>');
        fclose($fh);
      }
      $this->content = file_get_contents($this->path . '/data.html');
      if (is_file($this->path . '/data.json')) {
        $this->json = json_decode(file_get_contents($this->path . '/data.json'));
        $this->setTimestamp($this->json->timestamp);
      }
    }
  }

  public function getContent() {
    return $this->content;
  }

  public function setContent($content) {
    $this->content = $content;
  }

  public function getBody() {
    return trim(preg_replace("/\<h1\>" . $this->getTitle() . "\<\/h1\>/is", "", $this->getContent()));
  }

  public function getDate() {
    return date('d-m-Y', $this->getTimestamp());
  }

  public function getTime() {
    return date('H:s', $this->getTimestamp());
  }

  public function getTimestamp() {
    return $this->timestamp;
  }

  public function setTimestamp($timestamp) {
    $this->timestamp = $timestamp;
  }

  /**
   * Temporary.
   */
  public function render() {
    return str_replace(chr(13), '', $this->getContent());
  }

  public function setTitle($title) {
    $this->title = $title;
  }

  /**
   * Save this node on the file system.
   */
  public function save() {
    $this->saveHTML();
    $this->saveJSON();
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
    fwrite($fh, '<h1>' . $this->getTitle() . '</h1>') or die('Cannot write on file: ' . $this->realpath);
    fwrite($fh, $this->getBody());
    fclose($fh);

    new Message($this->env, 'user edited this file: ' . $_POST['edit-path'], MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');
  }

  /**
   * Save the JSON dump of the node
   */
  public function saveJSON() {
    if (!is_dir($this->realpath)) {
      mkdir($this->realpath) or die('cannot create dir: ' . $this->realpath);
    }
    $jsonpath = $this->realpath . '/data.json';
    $fh = fopen($jsonpath, 'w+');
    fwrite($fh, json_encode($this));
    fclose($fh);
  }

  /**
   * Delete this node from the file system.
   */
  public function delete() {
    $np = explode('/', $this->realpath);
    $rmname = '__' . $np[count($np) - 1];
    $np[count($np) - 1] = $rmname;
    // Delete file is indeed not an immediate deletion: it adds __ to folder name.
    // This is useful in order to recover a node that was accidentally deleted.
    rename($this->path, $this->env->dir['wiki'] . '/' . $rmname);
    rename($this->realpath, implode('/', $np));
    new Message($this->env, 'user deleted this page: ' . $this->name . '.', MESSAGE_GENERIC, MESSAGE_TYPE_LOG, 'node');
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
   * Get all the permissions for this node.
   * @return mixed
   */
  public function getPermissions() {
    $this->loadPermissions();
    return $this->permissions;
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
    if (isset($_POST['tmp_files'])) {
      $upload_dir = ($this->env->dir['tmp_files'] . '/' . $_POST['tmp_files']);
      if (!is_dir($upload_dir)) {
        mkdir($upload_dir);
      }
    }
    else {
      $upload_dir = $this->realpath;
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
   * Request to perform an action on the node. Check permissions and
   * execute the action accordingly.
   * @param $env Environment
   * @param $action string
   * @return string
   */
  public static function requestAction($env, $action, $nodedata) {
    $user = User::current($env);
    // TODO: refactor this stuff.

    // When user didn't enter a path...
    if (trim($nodedata['edit-path']) == '') {
      $path = $env->getCandidatePath($nodedata['edit-title']);
    }
    else {
      $path = $nodedata['edit-path'];
    }
    $node = new Node($env, $path, ($action == NODE_ACTION_ADD) ? $nodedata['edit-father'] : '');


    switch ($action) {
      case NODE_ACTION_ADD:
      case NODE_ACTION_EDIT:
        $parent = new Node($env, $nodedata['edit-father']);
        $datetime = strtotime($nodedata['edit-date'] . ' ' . $nodedata['edit-time']);
        $has_access = ($user->checkAccess(($action == NODE_ACTION_ADD) ? $parent : $node, $action));
        if ($has_access) {
          $node->setTitle($nodedata['edit-title']);
          $node->setContent('<h1>' . $nodedata['edit-title'] . '</h1>' . $nodedata['edit-content']);
          $node->setTimestamp($datetime > 0 ? $datetime : time());
          $node->save();
          $env->hook('node_save', array('node' => &$node, 'data' => $nodedata));

          $redirect = '/' . $node->getName() . '/';
        } else {
          $redirect = '/403';
        }

        break;



      case NODE_ACTION_DELETE:
        $has_access = ($user->checkAccess($node, NODE_ACTION_DELETE));
        if ($has_access) {
          $node->delete();
          $redirect = '/' . $node->father . '/';
        }
        break;
    }

    // TODO: refactor.
    if (isset($nodedata['tmp_files'])) {
      $tmp_dir = $env->dir['tmp_files'] . '/' . $nodedata['tmp_files'];
      if (is_dir($tmp_dir)) {
        $tmp_files = scandir($tmp_dir);
        foreach ($tmp_files as $file) {
          $fpath = $tmp_dir . '/' . $file;
          if (is_file($fpath)) {
            //print $env->dir['tmp_files'] . '/' . $file;
            copy($fpath, $node->realpath . '/' . $file);
            unlink($fpath);
          }
        }
        rmdir($tmp_dir);
      }
    }

    $response = new StdClass();
    $response->redirect = $redirect;
    $response_json = json_encode($response);

    return $response_json;
  }
}

