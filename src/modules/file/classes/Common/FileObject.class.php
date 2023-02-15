<?php
namespace Quanta\Common;

/**
 * Class File
 *
 * This class represents a File (of any type).
 */
class FileObject extends DataContainer {
  const FILE_RENDER_NAME = 'file_render_text';
  const FILE_RENDER_LINK = 'file_render_link';
  const FILE_RENDER_PATH = 'file_render_path';
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
  /** @var string $external */
  public $external = FALSE;



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

    if (strpos($file_path, '/') !== FALSE) {
      $this->external = TRUE;
      $this->setNode(NodeFactory::current($env));

    }
    elseif (empty($node)) {
      $this->setNode(NodeFactory::current($env));
    }
    else {
      $this->setNode($node);
    }
    $this->setFileName($name);
    $this->setPath($file_path);
    $exp = explode('.', $file_path);
    $this->setExtension(strtolower($exp[count($exp) - 1]));
    $this->setName (($name == NULL) ? $file_path : $name);
    $this->setType(FileObject::getFileType($this->extension));
    $this->exists = is_file($this->getRealPath());
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
   * Sets the file's type.
   *
   * @param string $file_type
   *   The file's type.
   */
  public function setType($file_type) {
    $this->type = $file_type;
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
   * Sets the file's path.
   *
   * @param string $path
   *   The file's path.
   */
  public function setPath($path) {
    $this->path = $path;
  }

  /**
   * Get the file's "real" system path.
   *
   * @return string
   *   The file full system path.
   */
  public function getRealPath() {
    return ($this->external) ? $this->path : ($this->node->path . '/' . $this->getPath());
  }

  /**
   * Gets the file's relative system path.
   *
   * @return string
   *   The full file's relative system path..
   */
  public function getRelativePath() {
	  return str_replace($this->env->dir['src'], '', $this->getRealPath());
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
   * Sets the file's extension.
   *
   * @param string $extension
   *   The file's extension.
   */
  public function setExtension($extension) {
    $this->extension = $extension;
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
      case 'svg':
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
  public function render($mode = self::FILE_RENDER_LINK) {
    switch ($mode) {

      case self::FILE_RENDER_PATH:
        $render = $this->getPath();
        break;

      case self::FILE_RENDER_NAME:
        $render = $this->getName();
      break;

      case self::FILE_RENDER_LINK:
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
   * Sets the file's name.
   *
   * @param string $name
   *   The file's name.
   */
  public function setName($name) {
    $this->name = $name;
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
      'm4u',
      'wma',
      'txt',
      'xls',
      'xlsx',
      'wav',
      'svg',
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
   * Gets the File name.
   *
   * @return string
   *   The file name.
   */
  public function getFileName() {
    return $this->filename;
  }

  /**
   * Sets the File name.
   *
   * @param $filename
   *   The file name.
   */
  public function setFileName($filename) {
    $this->filename = $filename;
  }

  /**
   * Gets the Node containing the file.
   *
   * @return Node
   *   The node.
   */
  public function getNode() {
    return $this->node;
  }

  /**
   * Sets the Node containing the file.
   *
   * @param Node $node
   *   The node.
   */
  public function setNode(Node $node) {
    $this->node = $node;
  }

  /**
   * Request the deletion of a file.
   * TODO: move access check.
   *
   * @param $node
   * @param $file string
   */
  public static function deleteFile($node, $file) {
    if (!NodeAccess::check($node->env, Node::NODE_ACTION_EDIT, array('node' => $node))) {
      new Message($node->env, t('Error: you have no permissions to delete files in this node'), Message::MESSAGE_ERROR);
    }
    unlink($node->path . '/' . $file);
  }

}
