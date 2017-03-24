<?php
define('FILE_RENDER_NAME', 'file_render_text');
define('FILE_RENDER_LINK', 'file_render_link');
define('FILE_RENDER_PATH', 'file_render_path');

/**
 * Class File
 * This class represents a Page (corrisponding to a rendered html page).
 */
class File {
  public $env;
  public $name;
  public $filename;
  public $path;
  public $extension;
  public $type;
  public $exists;
  public $size;
  /**
   * @var Node
   */
  public $node;

  // Construct the Node.
  public function __construct(&$env, $file_path, $node = NULL, $name = NULL) {
    $split = explode('/', $file_path);
    if ($node == NULL) {
      $this->node = (count($split) > 1) ?  NodeFactory::load($env, $split[count($split) - 2]) : NodeFactory::current($env);
    } else {
      $this->node = $node;
    }
    $this->filename = $split[count($split) - 1];
    $this->path = $file_path;
    $exp = explode('.', $file_path);
    $this->extension = strtolower($exp[count($exp) - 1]);
    $this->name = ($name == NULL) ? $file_path : $name;
    $this->type = File::getFileType($this->extension);
    $this->exists = is_file($this->node->realpath . '/' . $this->filename);
  }

  /**
   * Check if the file is public (meaning it can be viewed / downloaded).
   * System files such as .access .userdata and data.html should never be
   * public.
   *
   * @return bool
   */
  public function isPublic() {
    $is_public =
      ($this->getType() != 'swap') &&
      ($this->extension != 'html') &&
      ($this->extension != 'json') &&
      ($this->name != '');
    return $is_public;
  }


  public function getFileSize() {
    return $this->size;
  }

  public function getType() {
    return $this->type;
  }

  public function getPath() {
    return $this->path;
  }

	public function getFullPath() {
	  return '/' . $this->node->name . '/' . $this->getPath();
	}

  public function getExtension() {
    return $this->extension;
  }

  /**
   * Determine the file "type" from its extension.
   * @param $extension String
   * @return string
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

  public function getName() {
    return $this->name;
  }

  /**
   * Check the uploads being made to a node.
   */
  public static function checkUploads($node) {
    $user = User::current($node->env);
    if (!$user->checkAccess($node, NODE_ACTION_EDIT)) {
      new Message($node->env, 'Error: you have no permissions to upload files in this node', MESSAGE_ERROR);
    }
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

    $upload_dir = ($node->env->dir['tmp_files'] . '/' . $_REQUEST['tmp_files_dir']);

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
  public static function deleteFile($node, $file) {
    $user = User::current($node->env);
    if (!$user->checkAccess($node, NODE_ACTION_EDIT)) {
      new Message($node->env, 'Error: you have no permissions to upload files in this node', MESSAGE_ERROR);
    }
    unlink($node->realpath . '/' . $file);
  }

}
