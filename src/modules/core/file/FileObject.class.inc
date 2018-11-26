<?php
namespace Quanta\Common;

define('FILE_RENDER_NAME', 'file_render_text');
define('FILE_RENDER_LINK', 'file_render_link');
define('FILE_RENDER_PATH', 'file_render_path');

/**
 * Class File
 * This class represents a Page (corrisponding to a rendered html page).
 */
class FileObject extends DataContainer {
  /** @var string $name */
  public $name;
  /** @var string $filename */
  public $filename;
  /** @var string $path */
  public $path;
  /** @var string $extension */
  public $extension;
  /** @var string $type */
  public $type;
  /** @var boolean $exists */
  public $exists;
  /** @var double $size */
  public $size;
  /** @var Node $node */
  public $node;

  // Construct the File object.

  /**
   * File object constructor.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $file_path
   *   The file path.
   *
   * @param Node $node
   *   The node containing the file.
   *
   * @param string $name
   *   An optional custom name to assign to the file.
   */
  public function __construct(&$env, $file_path, $node = NULL, $name = NULL) {
    $this->env = $env;
    $split = explode('/', $file_path);
    if ($node == NULL) {
      $this->node = (count($split) > 1) ?  NodeFactory::load($env, $split[count($split) - 2]) : NodeFactory::current($env);
    } else {
      $this->node = $node;
    }
    $this->setFileName($split[count($split) - 1]);

    // TODO: set / get functions.
    $this->path = $file_path;
    $exp = explode('.', $file_path);
    $this->extension = strtolower($exp[count($exp) - 1]);
    $this->name = ($name == NULL) ? $file_path : $name;
    $this->type = FileObject::getFileType($this->extension);
    $this->exists = is_file($this->path);
  }

  /**
   * Check if the file is public (meaning it can be viewed / downloaded).
   *
   * @return bool
   *   Returns true if the file can be viewed / downloaded.
   */
  public function isPublic() {
    // TODO: implement real "public" check for the files.
    $is_public =
      ($this->getType() != 'swap') &&
      ($this->extension != 'html') &&
      ($this->extension != 'json') &&
      ($this->name != '');
    return $is_public;
  }


  /**
   * Get the file's size.
   *
   * @return float
   *   The file's size.
   */
  public function getFileSize() {
    $this->size = filesize($this->path);
    return $this->size;
  }

  /**
   * Gets the file's type.
   *
   * @return string
   *   The file's type.
   */
  public function getType() {
    return $this->type;
  }

  /**
   * Gets the file's path.
   *
   * @return string
   *   The file's path.
   */
  public function getPath() {
    return $this->path;
  }

  /**
   * Get the file's "real" system path.
   *
   * @return string
   *   The file full system path.
   */
  public function getRealPath() {
    return $this->node->path . '/' . $this->getPath();
  }

  /**
   * Gets the file's relative system path.
   *
   * @return string
   *   The full file's relative system path..
   */
  public function getFullPath() {
	  return '/' . $this->node->name . '/' . $this->getPath();
	}

  /**
   * Gets the file's extension.
   *
   * @return string
   *   The file's extension.
   */
  public function getExtension() {
    return $this->extension;
  }

  /**
   * Determine the file "type" from its extension.
   *
   * @param string $extension
   *   The file's extension.
   *
   * @return string
   *   The file type.
   */
  static function getFileType($extension) {
    switch ($extension) {
      case 'jpg':
      case 'png':
      case 'bmp':
      case 'gif':
      case 'jpeg':
      case 'ico':
      case 'raw':
        $type = 'image';
        break;

      case 'mp3':
      case 'wav':
      case 'm4a':
      case 'wma':
        $type = 'audio';
        break;

      case 'avi':
      case 'mov':
      case 'mp4':
      case 'mpg':
      case 'mpeg':
        $type = 'video';
        break;

      case 'html':
      case 'htm':
        $type = 'html';
        break;

      case 'doc':
      case 'odt':
      case 'docx':
      case 'txt':
      case 'rtf':
        $type = 'document';
        break;

      case 'pdf':
        $type = 'pdf';
        break;

      case 'xls':
      case 'xlsx':
      case 'xml':
        $type = 'sheet';
        break;

      case 'zip':
      case 'tex':
      case 'rar':
      case 'gz':
      case 'tar':
      case '7z':
        $type = 'archive';
        break;

      case 'swp':
        $type = 'swap';
        break;

      default:
        $type = 'data';
        break;
    }
    return $type;
  }

  /**
   * Renders the file as HTML.
   *
   * @param string $mode
   *   The rendering mode.
   *
   * @return string
   *   The rendered HTML version of the file.
   */
  public function render($mode = FILE_RENDER_LINK) {
    switch ($mode) {

      case FILE_RENDER_PATH:
        $render = $this->getPath();
        break;

      case FILE_RENDER_NAME:
        $render = $this->getName();
      break;

      case FILE_RENDER_LINK:
      default:
        $render = '<a href="' . $this->path . '">' . $this->getName() . '</a>';
        break;
    }
    return $render;
  }

  /**
   * Gets the file's name.
   *
   * @return string
   *   The file's name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Check the uploads being made to a node.
   * TODO: move access check in access hook.
   *
   * @param Environment $env
   *   The Environment.
   */
  public static function checkUploads($env) {
    // TODO: restore some kind of access check.
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

    // Create a temporary upload directory.
    $upload_dir = ($env->dir['tmp_files'] . '/' . $_REQUEST['tmp_upload_dir']);

    if (!is_dir($upload_dir)) {
      mkdir($upload_dir, 0755, TRUE);
    }

    foreach ($_FILES as $uploaded_file_name => $uploaded_file) {
      // Check all the uploaded files.
      if ($uploaded_file['error'] == 0) {
        $filename = pathinfo($uploaded_file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension), $allowed)) {
          echo '{"status":"error"}';
          exit;
        }
        // Move the uploaded file to the temporary directory..
        if (move_uploaded_file($uploaded_file['tmp_name'], $upload_dir . '/' . $filename . '.' . $extension)) {
          echo '{"status":"success"}';
          exit;
        }
      }
    }

    echo '{"status":"error"}';
    exit;
  }

  /**
   * @return string
   */
  public function getFileName() {
    return $this->filename;
  }

  /**
   * @param $filename
   */
  public function setFileName($filename) {
    $this->filename = $filename;
  }

  /**
   * Request the deletion of a file.
   * TODO: move access check.
   *
   * @param $node
   * @param $file string
   */
  public static function deleteFile($node, $file) {
    if (!NodeAccess::check($node->env, NODE_ACTION_EDIT, array('node' => $node))) {
      new Message($node->env, 'Error: you have no permissions to delete files in this node', MESSAGE_ERROR);
    }
    unlink($node->path . '/' . $file);
  }

}
